import { DemoStep, script } from './script';
import { FPS } from './theme';

export type Capture = {
    duration: number;
    markers: Record<string, number>;
    frostOutput: string;
};

export type Caption = { at: number; step: string; title: string; subtitle?: string };

export type PlannedSegment = {
    id: string;
    from: number;
    to: number;
    rate: number;
    frames: number;
    captions: Caption[];
    terminal?: { command: string; enterAt: number };
};

const at = (capture: Capture, marker: string, delay = 0): number => {
    const time = capture.markers[marker];
    if (undefined === time) {
        throw new Error(`The capture has no "${marker}" marker: run "castor video:capture" again.`);
    }

    return time + delay;
};

export const plan = (capture: Capture): PlannedSegment[] =>
    (script.demo as DemoStep[]).map((step) => {
        const from = at(capture, step.from.marker, step.from.delay);
        const to = at(capture, step.to.marker, step.to.delay);
        const rate = Math.max(1, (to - from) / step.maxSeconds);

        return {
            id: step.id,
            from,
            to,
            rate,
            frames: Math.round(((to - from) / rate) * FPS),
            captions: step.captions.map((caption) => ({ at: at(capture, caption.marker, caption.delay), step: step.step, title: caption.title, subtitle: caption.subtitle })),
            terminal: step.terminal ? { command: step.terminal.command, enterAt: at(capture, step.terminal.enterAt) } : undefined,
        };
    });

export const fixedSeconds = (): number => script.intro.seconds + script.principle.seconds + script.architecture.seconds + script.clients.seconds + script.outro.seconds;

export const totalFrames = (capture: Capture): number =>
    Math.round(fixedSeconds() * FPS + plan(capture).reduce((sum, segment) => sum + segment.frames, 0));
