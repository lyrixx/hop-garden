// Films the demo scenario in the running application, for the Remotion video.
//
// Writes public/capture/demo.mp4 and public/capture/markers.json (when each step happened, in seconds),
// which src/HopGarden.tsx reads to time its captions.
//
// Environment:
//   BASE_URL        https://hop-garden.test
//   RESET_COMMAND   castor demo:reset
//   FROST_COMMAND   castor demo:frost
//   CHROMIUM_PATH   a Chromium binary, when Playwright's own is not installed
import { execSync } from 'node:child_process';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const BASE_URL = process.env.BASE_URL ?? 'https://hop-garden.test';
const RESET_COMMAND = process.env.RESET_COMMAND ?? 'castor demo:reset';
const FROST_COMMAND = process.env.FROST_COMMAND ?? 'castor demo:frost';
const ROOT_DIR = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT_DIR = join(ROOT_DIR, 'video', 'public', 'capture');
const FRAMES_DIR = join(OUT_DIR, 'frames');
// 1920×1080 frames of a page zoomed 1.5×: the app reads like on a 1280×720 screen
const VIEWPORT = { width: 1920, height: 1080 };
const ZOOM = 1.5;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const run = (command) => execSync(command, { cwd: ROOT_DIR, encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] });

// A visible cursor: headless Chromium draws none
const CURSOR_SCRIPT = `
// zoom: ${ZOOM}
(() => {
    const install = () => {
        if (document.getElementById('demo-cursor')) return;
        const style = document.createElement('style');
        style.textContent = 'html { zoom: ${ZOOM}; } #demo-cursor { zoom: ${1 / ZOOM}; }'
            + '.sf-toolbar, .sf-toolbarreset, .sf-minitoolbar { display: none !important; }'
            + '#demo-cursor { position: fixed; z-index: 2147483647; pointer-events: none; width: 26px; height: 26px; margin: -3px 0 0 -4px; transition: transform .08s; }'
            + '#demo-cursor.pressed { transform: scale(.82); }'
            + '#demo-cursor::after { content: ""; position: absolute; left: -14px; top: -14px; width: 36px; height: 36px; border-radius: 50%; background: rgb(76 154 99 / 35%); opacity: 0; transform: scale(.4); transition: opacity .3s, transform .3s; }'
            + '#demo-cursor.pressed::after { opacity: 1; transform: scale(1); }';
        document.head.append(style);
        const cursor = document.createElement('div');
        cursor.id = 'demo-cursor';
        cursor.innerHTML = '<svg viewBox="0 0 24 24" width="26" height="26"><path d="M4 2l16 10-7 1.5 4 7.5-3 1.5-4-7.5L4 20z" fill="#1d2420" stroke="white" stroke-width="1.5" stroke-linejoin="round"/></svg>';
        const [x, y] = JSON.parse(sessionStorage.getItem('demo-cursor') ?? '[960, 900]');
        cursor.style.left = x + 'px';
        cursor.style.top = y + 'px';
        document.body.append(cursor);
        // Turbo Drive replaces the body: the cursor moves to the new one
        addEventListener('turbo:before-render', (event) => event.detail.newBody.append(cursor));
        addEventListener('mousemove', (event) => {
            cursor.style.left = event.clientX + 'px';
            cursor.style.top = event.clientY + 'px';
            sessionStorage.setItem('demo-cursor', JSON.stringify([event.clientX, event.clientY]));
        }, true);
        addEventListener('mousedown', () => cursor.classList.add('pressed'), true);
        addEventListener('mouseup', () => setTimeout(() => cursor.classList.remove('pressed'), 180), true);
    };
    document.readyState === 'loading' ? addEventListener('DOMContentLoaded', install) : install();
})();
`;

console.log(`Resetting the demo data: ${RESET_COMMAND}`);
run(RESET_COMMAND);

rmSync(OUT_DIR, { recursive: true, force: true });
mkdirSync(FRAMES_DIR, { recursive: true });

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const context = await browser.newContext({ viewport: VIEWPORT, ignoreHTTPSErrors: true });
await context.addInitScript(CURSOR_SCRIPT);
const page = await context.newPage();

// Start from an empty conversation, before filming
await page.goto(BASE_URL);
await page.click('button:has-text("New conversation")');
await page.waitForSelector('.chat-empty');

const frames = [];
const markers = {};
const mark = (name) => {
    markers[name] = Date.now() / 1000;
    console.log(`  ${name}`);
};

const cdp = await context.newCDPSession(page);
cdp.on('Page.screencastFrame', async ({ data, metadata, sessionId }) => {
    const file = join(FRAMES_DIR, `${String(frames.length).padStart(6, '0')}.jpg`);
    writeFileSync(file, Buffer.from(data, 'base64'));
    frames.push({ file, timestamp: metadata.timestamp });
    await cdp.send('Page.screencastFrameAck', { sessionId }).catch(() => {});
});
await cdp.send('Page.startScreencast', { format: 'jpeg', quality: 92, maxWidth: VIEWPORT.width, maxHeight: VIEWPORT.height });

// The care sheet card is taller than the screen: a target out of the band left clear by the sticky top bar and chat
// form scrolls to the middle of the screen first, smoothly, so that the video shows the way to it
const reveal = async (target) => {
    const hidden = await target.evaluate((element) => {
        const { top, bottom } = element.getBoundingClientRect();
        const topbar = document.querySelector('.topbar')?.getBoundingClientRect().bottom ?? 0;
        const form = document.querySelector('.chat-form');

        return !form?.contains(element) && (top < topbar || bottom > Math.min(form?.getBoundingClientRect().top ?? innerHeight, innerHeight));
    });
    if (hidden) {
        await target.evaluate((element) => element.scrollIntoView({ behavior: 'smooth', block: 'center' }));
        await sleep(900);
    }
};
const moveTo = async (selector) => {
    const target = page.locator(selector).first();
    await reveal(target);
    const box = await target.boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 30 });
    await sleep(250);
};
const click = async (selector) => {
    await moveTo(selector);
    await page.mouse.down();
    await sleep(90);
    await page.mouse.up();
};
const idle = () => page.waitForFunction(() => !document.querySelector('.chat')?.classList.contains('is-busy'), null, { timeout: 180000 });
const ask = async (text) => {
    await click('input[name="text"]');
    mark(`type_${text}`);
    await page.keyboard.type(text, { delay: 110 });
    await sleep(350);
    await click('button:has-text("Send")');
    mark(`sent_${text}`);
};

mark('start');
await sleep(1500);

await ask('hortensia');
await page.waitForSelector('.tool-call', { timeout: 120000 });
mark('tool_search');
await page.waitForSelector('.bubble-assistant', { timeout: 180000 });
mark('question');
await idle();
mark('question_done');
await sleep(2500);

await ask('paniculata');
await page.waitForFunction(() => [...document.querySelectorAll('.tool-call code')].some((code) => code.textContent.startsWith('propose_plant')), null, { timeout: 180000 });
mark('tool_propose');
await page.waitForSelector('article.plant-card', { timeout: 180000 });
mark('card');
await idle();
mark('card_done');
await page.evaluate(() => {
    const card = document.querySelector('article.plant-card');
    window.scrollTo({ top: window.scrollY + card.getBoundingClientRect().top - 24, behavior: 'smooth' });
});
await sleep(1500);
await moveTo('article.plant-card tr.needs-review');
await sleep(2500);

mark('validate');
await click('article.plant-card button:has-text("Validate")');
await page.waitForSelector('article.plant-card.is-validated');
mark('validated');
await sleep(1800);

// Validated in its frame, the card shows its reminder switches, there in the conversation
mark('reminders');
await sleep(1200);
for (const type of ['pruning', 'frost']) {
    const reminder = `article.plant-card form[action$="/reminders/${type}"]`;
    await click(`${reminder} button`);
    await page.waitForSelector(`${reminder}.is-enabled`);
    mark(`reminder_${type}`);
    await sleep(1000);
}
await page.mouse.move(VIEWPORT.width * 0.62, VIEWPORT.height * 0.55, { steps: 20 });
await sleep(800);

mark('frost_command');
const frostOutput = run(FROST_COMMAND);
await page.waitForSelector('.toast', { timeout: 30000 });
mark('toast');
await sleep(5000);
mark('end');

await cdp.send('Page.stopScreencast');
await browser.close();

// Frames come when the page changes: each one lasts until the next
const origin = frames[0].timestamp;
const end = markers.end;
const list = frames.map((frame, i) => {
    const next = i + 1 < frames.length ? frames[i + 1].timestamp : end;

    return `file '${frame.file}'\nduration ${Math.max(0.001, next - frame.timestamp).toFixed(4)}`;
});
list.push(`file '${frames[frames.length - 1].file}'`);
writeFileSync(join(OUT_DIR, 'frames.txt'), list.join('\n') + '\n');
run(`ffmpeg -y -loglevel error -f concat -safe 0 -i "${join(OUT_DIR, 'frames.txt')}" -fps_mode cfr -r 30 -c:v libx264 -preset slow -crf 16 -pix_fmt yuv420p "${join(OUT_DIR, 'demo.mp4')}"`);
rmSync(FRAMES_DIR, { recursive: true, force: true });
rmSync(join(OUT_DIR, 'frames.txt'));

const ok = frostOutput.replace(/\x1b\[[0-9;]*m/g, '').match(/\[OK\][^\n]*/);
const relative = Object.fromEntries(Object.entries(markers).map(([name, at]) => [name, Math.round((at - origin) * 1000) / 1000]));
writeFileSync(join(OUT_DIR, 'markers.json'), JSON.stringify({
    duration: relative.end,
    markers: relative,
    frostOutput: ok ? ok[0].trim() : '[OK] Reminders checked.',
}, null, 4) + '\n');

console.log(`Captured ${frames.length} frames, ${relative.end.toFixed(1)} s: video/public/capture/demo.mp4`);
