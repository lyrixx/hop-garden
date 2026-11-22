import { Img, interpolate, spring, staticFile, useCurrentFrame, useVideoConfig } from 'remotion';
import { colors, serif } from '../theme';
import { Card } from './Card';

/**
 * A hand-coloured plate of the Biodiversity Heritage Library (public domain), paper whitened, colours lifted, on a
 * crowned card. Keep the files of public/plates/ in sync with assets/images/plates/.
 */
export const Plate = ({ plate, caption, fig = 1, width = 560 }: { plate: string; caption: string; fig?: number; width?: number }) => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const progress = spring({ frame: frame - 6, fps, config: { damping: 200 } });

    return (
        <div style={{ opacity: progress, transform: `translateY(${interpolate(progress, [0, 1], [24, 0])}px)` }}>
            <Card crown radius={40} style={{ width, padding: '28px 28px 22px' }}>
                <figure style={{ margin: 0, textAlign: 'center' }}>
                    <div style={{ padding: 22, borderRadius: 28, background: colors.cream }}>
                        {/* The white of the paper takes the colour of the card */}
                        <Img src={staticFile(`plates/${plate}.webp`)} style={{ display: 'block', width: '100%', mixBlendMode: 'multiply' }} />
                    </div>
                    <figcaption style={{ marginTop: 18, fontFamily: serif, fontSize: 20, fontWeight: 600, letterSpacing: 4, textTransform: 'uppercase', color: colors.muted }}>
                        <span style={{ fontStyle: 'italic', textTransform: 'none', letterSpacing: 0, color: colors.aubergine, marginRight: 10, fontSize: 24 }}>Fig. {fig}</span>
                        {caption}
                    </figcaption>
                </figure>
            </Card>
        </div>
    );
};
