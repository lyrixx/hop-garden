import { CalculateMetadataFunction, Composition, staticFile } from 'remotion';
import { HopGarden, HopGardenProps } from './HopGarden';
import { FPS, HEIGHT, WIDTH } from './theme';
import { Capture, totalFrames } from './timeline';

// The length of the demo depends on the capture: a real LLM takes its time
const calculateMetadata: CalculateMetadataFunction<HopGardenProps> = async () => {
    const response = await fetch(staticFile('capture/markers.json'));
    const capture = (await response.json()) as Capture;

    return { durationInFrames: totalFrames(capture), props: { capture } };
};

export const Root = () => (
    <Composition
        id="HopGarden"
        component={HopGarden}
        defaultProps={{ capture: null }}
        calculateMetadata={calculateMetadata}
        durationInFrames={90 * FPS}
        fps={FPS}
        width={WIDTH}
        height={HEIGHT}
    />
);
