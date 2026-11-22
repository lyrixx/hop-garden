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
    // The frames of the capture shown, from startFrom to before endAt
    startFrom: number;
    endAt: number;
    rate: number;
    frames: number;
    // Frames of the demo before this segment, of all the segments: the slow zoom goes on across the cuts
    offset: number;
    demoFrames: number;
    captions: Caption[];
    terminal?: { command: string; enterAt: number; leaveAt: number };
};

const at = (capture: Capture, marker: string, delay = 0): number => {
    const time = capture.markers[marker];
    if (undefined === time) {
        throw new Error(`The capture has no "${marker}" marker: run "castor video:capture" again.`);
    }

    return time + delay;
};

export const plan = (capture: Capture): PlannedSegment[] => {
    const segments = (script.demo as DemoStep[]).map((step) => {
        const from = at(capture, step.from.marker, step.from.delay);
        const to = at(capture, step.to.marker, step.to.delay);
        const rate = Math.max(1, (to - from) / step.maxSeconds);
        const startFrom = Math.round(from * FPS);
        const endAt = Math.round(to * FPS);

        return {
            id: step.id,
            from,
            to,
            startFrom,
            endAt,
            rate,
            // Rounded down: a frame past endAt would show a blank window
            frames: Math.floor((endAt - startFrom) / rate),
            captions: step.captions.map((caption) => ({ at: at(capture, caption.marker, caption.delay), step: step.step, title: caption.title, subtitle: caption.subtitle })),
            terminal: step.terminal
                ? { command: step.terminal.command, enterAt: at(capture, step.terminal.enterAt), leaveAt: at(capture, step.terminal.leaveAt.marker, step.terminal.leaveAt.delay) }
                : undefined,
        };
    });
    const demoFrames = segments.reduce((sum, segment) => sum + segment.frames, 0);
    let offset = 0;

    return segments.map((segment) => {
        const planned = { ...segment, offset, demoFrames };
        offset += segment.frames;

        return planned;
    });
};

export const fixedSeconds = (): number => script.intro.seconds + script.principle.seconds + script.architecture.seconds + script.clients.seconds + script.outro.seconds;

export const totalFrames = (capture: Capture): number => {
    const segments = plan(capture);

    return Math.round(fixedSeconds() * FPS) + (segments.at(-1)?.demoFrames ?? 0);
};
