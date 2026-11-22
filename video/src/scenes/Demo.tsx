import { OffthreadVideo, Sequence, interpolate, staticFile, useCurrentFrame, useVideoConfig } from 'remotion';
import { Background } from '../components/Background';
import { BROWSER, BrowserWindow } from '../components/BrowserWindow';
import { CaptionBar } from '../components/CaptionBar';
import { Terminal } from '../components/Terminal';
import { PlannedSegment } from '../timeline';

// A step faster than this (no LLM latency) would flash its caption
const MIN_CAPTION_SECONDS = 2.2;

/**
 * Each caption shows from its step, but not before the previous one stayed on screen long enough.
 */
const scheduleCaptions = (starts: number[], total: number, minFrames: number): Array<{ from: number; frames: number }> => {
    const froms: number[] = [];
    starts.forEach((start, i) => froms.push(Math.min(total - 1, i > 0 ? Math.max(start, froms[i - 1] + minFrames) : start)));

    return froms.map((from, i) => ({ from, frames: Math.max(1, (i + 1 < froms.length ? froms[i + 1] : total) - from) }));
};

/**
 * One segment of the capture in the browser window, with the captions placed when their step happened.
 */
export const DemoSegment = ({ segment, frostOutput }: { segment: PlannedSegment; frostOutput: string }) => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const toFrame = (at: number) => Math.max(0, Math.round(((at - segment.from) / segment.rate) * fps));
    const zoom = interpolate(frame, [0, segment.frames], [1, 1.025]);

    return (
        <Background>
            <BrowserWindow>
                <div style={{ position: 'absolute', inset: 0, transform: `scale(${zoom})`, transformOrigin: '50% 40%' }}>
                    <OffthreadVideo
                        src={staticFile('capture/demo.mp4')}
                        startFrom={Math.round(segment.from * fps)}
                        endAt={Math.round(segment.to * fps)}
                        playbackRate={segment.rate}
                        muted
                        style={{ width: BROWSER.width, height: (BROWSER.width * 9) / 16 }}
                    />
                </div>
            </BrowserWindow>

            {scheduleCaptions(segment.captions.map((caption) => toFrame(caption.at)), segment.frames, Math.round(MIN_CAPTION_SECONDS * fps)).map(({ from, frames }, i) => {
                const caption = segment.captions[i];

                return (
                    <Sequence key={caption.title} from={from} durationInFrames={frames} layout="none">
                        <CaptionBar step={caption.step} title={caption.title} subtitle={caption.subtitle} />
                    </Sequence>
                );
            })}

            {segment.terminal && (
                <Sequence from={0} layout="none">
                    <Terminal
                        command={segment.terminal.command}
                        output={frostOutput}
                        enterAt={toFrame(segment.terminal.enterAt)}
                        style={{ position: 'absolute', left: 150, bottom: 70, width: 760 }}
                    />
                </Sequence>
            )}
        </Background>
    );
};
