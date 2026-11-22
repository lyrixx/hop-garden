import { Series } from 'remotion';
import { Architecture } from './scenes/Architecture';
import { Clients } from './scenes/Clients';
import { DemoSegment } from './scenes/Demo';
import { Intro } from './scenes/Intro';
import { Outro } from './scenes/Outro';
import { Principle } from './scenes/Principle';
import { FPS } from './theme';
import { script } from './script';
import { Capture, plan } from './timeline';

export type HopGardenProps = { capture: Capture | null };

const frames = (seconds: number) => Math.round(seconds * FPS);

export const HopGarden = ({ capture }: HopGardenProps) => {
    if (null === capture) {
        throw new Error('No capture: run "castor video:capture" first.');
    }

    return (
        <Series>
            <Series.Sequence durationInFrames={frames(script.intro.seconds)}>
                <Intro />
            </Series.Sequence>
            <Series.Sequence durationInFrames={frames(script.principle.seconds)}>
                <Principle />
            </Series.Sequence>
            {plan(capture).map((segment) => (
                <Series.Sequence key={segment.id} durationInFrames={segment.frames}>
                    <DemoSegment segment={segment} frostOutput={capture.frostOutput} />
                </Series.Sequence>
            ))}
            <Series.Sequence durationInFrames={frames(script.architecture.seconds)}>
                <Architecture />
            </Series.Sequence>
            <Series.Sequence durationInFrames={frames(script.clients.seconds)}>
                <Clients />
            </Series.Sequence>
            <Series.Sequence durationInFrames={frames(script.outro.seconds)}>
                <Outro />
            </Series.Sequence>
        </Series>
    );
};
