// Films the demo scenario in the running application, for the Remotion video.
//
// Writes public/capture/demo.mp4 and public/capture/markers.json (when each step happened, in seconds),
// which src/HopGarden.tsx reads to time its captions.
//
// The scenario needs the data of "castor demo:reset": a member, no care sheet of a hydrangea yet, and a space
// "Terrace". It follows the real LLM: each step waits for what the agent shows, and the capture fails, with a screenshot
// and the text of the chat, when the agent goes another way (run it again).
//
// Environment:
//   BASE_URL        https://hop-garden.test (the chat of a garden is on /g/{garden}/assistant)
//   GARDEN          the-allotment, the slug of the garden of the demo in its URLs
//   RESET_COMMAND   castor demo:reset
//   FROST_COMMAND   castor demo:frost
//   LOGIN_EMAIL     alice@hop-garden.test, of the demo: the owner of "The allotment", which Bob shares
//   LOGIN_PASSWORD  hop-garden-demo
//   CHROMIUM_PATH   a Chromium binary, when Playwright's own is not installed
import { exec, execSync } from 'node:child_process';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { chromium } from 'playwright';

const BASE_URL = process.env.BASE_URL ?? 'https://hop-garden.test';
const RESET_COMMAND = process.env.RESET_COMMAND ?? 'castor demo:reset';
const FROST_COMMAND = process.env.FROST_COMMAND ?? 'castor demo:frost';
// Keep in sync with src/Story/DemoStory.php and App\Factory\UserFactory::PASSWORD
const LOGIN_EMAIL = process.env.LOGIN_EMAIL ?? 'alice@hop-garden.test';
const LOGIN_PASSWORD = process.env.LOGIN_PASSWORD ?? 'hop-garden-demo';
// The URLs of a garden are under its slug: "The allotment" of the demo
const GARDEN = process.env.GARDEN ?? 'the-allotment';
const ROOT_DIR = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT_DIR = join(ROOT_DIR, 'video', 'public', 'capture');
const FRAMES_DIR = join(OUT_DIR, 'frames');
// 1920×1080 frames of a page zoomed 1.5×: the app reads like on a 1280×720 screen
const VIEWPORT = { width: 1920, height: 1080 };
const ZOOM = 1.5;
// Keep in sync with the space of src/Story/DemoStory.php, and the option of the species with the candidates
// of fixtures/http/trefle/search/hydrangea.json
const MESSAGE = 'I just planted a hydrangea on the terrace';
const SPACE = 'Terrace';
const SPECIES = /paniculata/i;
// A real LLM takes its time, a care sheet longest
const AGENT_TIMEOUT = 180000;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const run = (command) => execSync(command, { cwd: ROOT_DIR, encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] });
// The screencast goes on while the command runs: execSync would stop the frames
const runAsync = async (command) => (await promisify(exec)(command, { cwd: ROOT_DIR, encoding: 'utf8' })).stdout;

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

// Logged in, then an empty conversation, before filming
await page.goto(`${BASE_URL}/login`);
await page.fill('input[name="_username"]', LOGIN_EMAIL);
await page.fill('input[name="_password"]', LOGIN_PASSWORD);
await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login')), page.click('form[action$="/login"] button[type="submit"]')]);
await page.goto(`${BASE_URL}/g/${GARDEN}/assistant`);
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

// Waits for `predicate(arg)` in the page, or for an error of the chat, which fails the capture
const until = async (what, predicate, arg = null, timeout = AGENT_TIMEOUT) => {
    try {
        await page.waitForFunction(`!!document.querySelector('.bubble-error') || (${predicate})(${JSON.stringify(arg)})`, null, { timeout });
    } catch {
        throw new Error(`Timed out waiting for ${what}.`);
    }
    const error = page.locator('.bubble-error');
    if (await error.count()) {
        throw new Error(`The chat shows an error while waiting for ${what}: ${await error.first().innerText()}`);
    }
};
const untilSelector = (what, selector, timeout) => until(what, (selector) => null !== document.querySelector(selector), selector, timeout);
const count = (selector) => page.locator(selector).count();
// A call of an MCP tool, after the `after` first ones of the conversation
const untilTool = (name, after) =>
    until(`a call of ${name}`, ({ name, after }) => [...document.querySelectorAll('.tool-call code')].slice(after).some((code) => code.textContent.startsWith(`${name}(`)), { name, after });
// The agent may answer twice, or replace a card by another: a take for the video shows each step once
const expectOne = async (what, selector) => {
    const found = await count(selector);
    if (1 !== found) {
        throw new Error(`The chat shows ${found} ${what}s, instead of one.`);
    }
};
const idle = () => until('the end of the answer', () => !document.querySelector('.chat')?.classList.contains('is-busy'));

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
        await sleep(1000);
    }
};
const moveTo = async (target) => {
    target = 'string' === typeof target ? page.locator(target).first() : target;
    await reveal(target);
    const box = await target.boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 30 });
    await sleep(250);
};
const click = async (target) => {
    await moveTo(target);
    await page.mouse.down();
    await sleep(90);
    await page.mouse.up();
};
// Scrolls an element to the top of the screen, under the sticky top bar
const scrollToTop = async (selector) => {
    await page.evaluate((selector) => {
        const element = document.querySelector(selector);
        const topbar = document.querySelector('.topbar')?.getBoundingClientRect().bottom ?? 0;
        window.scrollTo({ top: window.scrollY + element.getBoundingClientRect().top - topbar - 16, behavior: 'smooth' });
    }, selector);
    await sleep(1200);
};

const film = async () => {
    mark('start');
    await sleep(1500);

    // 1. The user says what they planted: the agent looks for a care sheet, then searches Trefle, and asks which
    // hydrangea with options to tap
    await click('input[name="text"]');
    mark('type');
    await page.keyboard.type(MESSAGE, { delay: 70 });
    await sleep(400);
    await click('button:has-text("Send")');
    mark('sent');
    await untilSelector('the first tool call', '.tool-call');
    mark('tool_first');
    await untilTool('search_plant', 0);
    mark('tool_search');
    await untilSelector('the question of the species', '.chat-choices');
    mark('choices');
    await idle();
    mark('choices_done');
    await expectOne('question', '.chat-choices');
    const options = await page.locator('.chat-choices .chat-choice').allInnerTexts();
    console.log(`    options: ${options.join(' | ')}`);
    await sleep(1800);

    // 2. A tap on the species: the agent completes the care sheet and proposes it as a draft
    const option = page.locator('.chat-choices:not(.is-answered) .chat-choice:not([disabled])').filter({ hasText: SPECIES });
    if (0 === (await option.count())) {
        throw new Error(`No option matches ${SPECIES}: ${options.join(' | ')}.`);
    }
    const toolsBeforeDraft = await count('.tool-call');
    mark('tap_species');
    await click(option.first());
    await untilTool('propose_plant', toolsBeforeDraft);
    mark('tool_propose');
    await untilSelector('the draft card', 'article.plant-card.is-draft');
    mark('card');
    await idle();
    mark('card_done');
    await expectOne('care sheet card', 'article.plant-card');
    await sleep(800);

    // The values to check, highlighted
    mark('review');
    await scrollToTop('article.plant-card');
    await moveTo('article.plant-card tr.needs-review');
    await sleep(2200);

    // 3. Validate: the card tells the agent, which finds the space Terrace and proposes the plant
    const toolsBeforePlanting = await count('.tool-call');
    mark('validate');
    await click('article.plant-card.is-draft button:has-text("Validate")');
    await untilSelector('the validated care sheet', 'article.plant-card.is-validated', 30000);
    mark('validated');
    await untilTool('propose_planting', toolsBeforePlanting);
    mark('tool_planting');
    await untilSelector('the "Add to my garden" card', 'article.proposal-card form button');
    mark('planting_card');
    await idle();
    mark('planting_card_done');
    await expectOne('"Add to my garden" card', '.chat-proposal');
    const facts = await page.locator('article.proposal-card .proposal-facts').last().innerText();
    if (!facts.includes(SPACE)) {
        throw new Error(`The "Add to my garden" card is not in the space ${SPACE}: ${facts}`);
    }
    await moveTo(page.locator('article.proposal-card .proposal-facts > div').last());
    await sleep(1500);

    // 4. A tap adds the plant: the card says "Added", the agent confirms
    const answersBeforeAdded = await count('.bubble-assistant');
    mark('add');
    await click(page.locator('article.proposal-card form button').last());
    await untilSelector('the added plant', 'article.proposal-card.is-done', 30000);
    mark('added');
    await until('the confirmation of the agent', (before) => document.querySelectorAll('.bubble-assistant').length > before, answersBeforeAdded);
    await idle();
    mark('added_done');
    await expectOne('"Add to my garden" card', '.chat-proposal');
    await moveTo('article.proposal-card.is-done .proposal-card-head');
    await sleep(1500);

    // 5. Its reminders, on the validated care sheet up in the conversation: frost on the bud, pruning on the stem
    mark('reminders');
    for (const type of ['frost', 'pruning']) {
        const reminder = `article.plant-card form[action$="/reminders/${type}"]`;
        await click(`${reminder} button`);
        await untilSelector(`the ${type} reminder`, `${reminder}.is-enabled`, 30000);
        mark(`reminder_${type}`);
        await sleep(1100);
    }

    // 6. My plant, apart from the agent: its space, its neighbours (the lemon tree of the toast), its recommendations
    mark('see_plant');
    await click('article.proposal-card.is-done a:has-text("See my plant")');
    await untilSelector('the page of my plant', '.planting-card', 30000);
    mark('my_plant');
    await sleep(1200);
    await moveTo('.planting-place tr:last-child a');
    await sleep(1800);
    await page.mouse.move(VIEWPORT.width * 0.55, VIEWPORT.height * 0.62, { steps: 20 });
    await sleep(600);

    // 7. The reminder check against the recorded cold snap: a frost toast, live, about the lemon tree of the terrace
    mark('frost_command');
    const frost = runAsync(FROST_COMMAND);
    // Awaited at the end: a failure until then must not end the process before the screenshot of the failure
    frost.catch(() => {});
    await untilSelector('the frost toast', '.toast-frost', 60000);
    mark('toast');
    await sleep(1800);
    await moveTo('.toast-frost a');
    await sleep(3200);
    mark('end');

    return frost;
};

let frostOutput;
try {
    frostOutput = await film();
} catch (error) {
    await page.screenshot({ path: join(OUT_DIR, 'failure.png') }).catch(() => {});
    const log = await page.locator('#chat-log').innerText().catch(() => '(no chat on this page)');
    writeFileSync(join(OUT_DIR, 'failure.txt'), `${error.message}\n\n${log}\n`);
    console.error(`${error.message}\nThe screen and the chat are in video/public/capture/failure.png and failure.txt. Run the capture again.`);
    await browser.close();
    process.exit(1);
}

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
