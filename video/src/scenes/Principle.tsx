import { interpolate, spring, useCurrentFrame, useVideoConfig } from 'remotion';
import { Background } from '../components/Background';
import { script } from '../script';
import { colors, serif } from '../theme';

export const Principle = () => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const lines = [...script.principle.lines, script.principle.punchline];

    return (
        <Background>
            <div style={{ position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', justifyContent: 'center', alignItems: 'flex-start', paddingLeft: 220, gap: 18, fontFamily: serif }}>
                {lines.map((line, i) => {
                    const progress = spring({ frame: frame - i * 20, fps, config: { damping: 200 } });
                    const punchline = i === lines.length - 1;
                    // The punchline gets a magenta marker, the colour of the leaves crowning the cards
                    const highlight = interpolate(frame, [i * 20 + 12, i * 20 + 30], [0, 100], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

                    return (
                        <div key={line} style={{ marginTop: punchline ? 16 : 0, fontSize: 104, fontWeight: 800, letterSpacing: -3, lineHeight: 1.1, color: colors.white, opacity: progress, transform: `translateX(${interpolate(progress, [0, 1], [-40, 0])}px)` }}>
                            <span
                                style={
                                    punchline
                                        ? { backgroundImage: `linear-gradient(${colors.magenta}, ${colors.magenta})`, backgroundRepeat: 'no-repeat', backgroundSize: `${highlight}% 100%`, padding: '0 22px 8px', marginLeft: -22, borderRadius: 26 }
                                        : undefined
                                }
                            >
                                {line}
                            </span>
                        </div>
                    );
                })}
            </div>
        </Background>
    );
};
