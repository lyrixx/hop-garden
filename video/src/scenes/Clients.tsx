import { interpolate, spring, useCurrentFrame, useVideoConfig } from 'remotion';
import { Background } from '../components/Background';
import { Terminal } from '../components/Terminal';
import { script } from '../script';
import { colors, font, mono, serif, shadow } from '../theme';

const TOOLS = script.clients.capabilities;
const OTHERS = script.clients.resources;

export const Clients = () => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const title = spring({ frame, fps, config: { damping: 200 } });

    return (
        <Background>
            <div style={{ position: 'absolute', top: 80, left: 160, right: 160, color: colors.white, opacity: title }}>
                <div style={{ fontFamily: serif, fontSize: 60, fontWeight: 800, letterSpacing: -1.5 }}>{script.clients.title}</div>
                <div style={{ fontFamily: serif, fontSize: 30, fontWeight: 500, color: colors.textSoft, marginTop: 10 }}>{script.clients.subtitle}</div>
            </div>

            <Terminal command={script.clients.command} enterAt={fps * 2.2} style={{ position: 'absolute', top: 290, left: 160, width: 1600 }} />

            <div style={{ position: 'absolute', top: 500, left: 160, width: 1600, display: 'flex', flexWrap: 'wrap', gap: 16 }}>
                {[...TOOLS, ...OTHERS].map((name, i) => {
                    const progress = spring({ frame: frame - fps * 2.6 - i * 3, fps, config: { damping: 200 } });
                    const external = 'confirm_plant' === name;
                    const other = OTHERS.includes(name);

                    return (
                        <span
                            key={name}
                            style={{
                                fontFamily: mono,
                                fontSize: 28,
                                padding: '10px 24px',
                                borderRadius: 999,
                                background: other ? colors.aniseSoft : external ? colors.aubergine : colors.white,
                                color: external ? colors.white : colors.ink,
                                boxShadow: shadow,
                                opacity: progress,
                                transform: `translateY(${interpolate(progress, [0, 1], [16, 0])}px)`,
                            }}
                        >
                            {name}
                        </span>
                    );
                })}
            </div>
            <div style={{ position: 'absolute', top: 800, left: 160, padding: '12px 26px', borderRadius: 999, background: colors.veil, fontFamily: font, fontSize: 26, fontWeight: 700, color: colors.pink, opacity: interpolate(frame, [fps * 4, fps * 4.5], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }) }}>
                {script.clients.note}
            </div>
        </Background>
    );
};
