import { spring, useCurrentFrame, useVideoConfig } from 'remotion';
import { Background } from '../components/Background';
import { Hop } from '../components/Hop';
import { script } from '../script';
import { colors, font, mono, serif, shadow } from '../theme';

export const Outro = () => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const progress = spring({ frame, fps, config: { damping: 200 } });
    const credits = spring({ frame: frame - 20, fps, config: { damping: 200 } });

    return (
        <Background dark>
            <div style={{ position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', paddingBottom: 90, color: colors.white, opacity: progress }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 28 }}>
                    <span style={{ width: 130, height: 130, borderRadius: '50%', background: colors.white, color: colors.forest, display: 'grid', placeItems: 'center', boxShadow: shadow }}>
                        <Hop size={115} strokeWidth={1.5} />
                    </span>
                    <span style={{ fontFamily: serif, fontSize: 124, fontWeight: 800, letterSpacing: -4 }}>{script.outro.title}</span>
                </div>
                <div style={{ fontFamily: mono, fontSize: 42, marginTop: 34, padding: '14px 34px', borderRadius: 999, background: colors.white, color: colors.forestDeep, fontWeight: 700, boxShadow: shadow }}>{script.outro.repository}</div>
                <div style={{ fontFamily: font, fontSize: 34, fontWeight: 600, marginTop: 28, color: colors.anise }}>{script.outro.tagline}</div>
            </div>
            <div style={{ position: 'absolute', left: 192, right: 192, bottom: 50, padding: '18px 30px', borderRadius: 28, background: colors.veil, fontFamily: font, fontSize: 22, color: colors.textSoft, opacity: credits, textAlign: 'center', lineHeight: 1.6 }}>
                {script.outro.credits.map((line) => (
                    <div key={line}>{line}</div>
                ))}
            </div>
        </Background>
    );
};
