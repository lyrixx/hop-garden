import { interpolate, spring, useCurrentFrame, useVideoConfig } from 'remotion';
import { Background } from '../components/Background';
import { Hop } from '../components/Hop';
import { Plate } from '../components/Plate';
import { script } from '../script';
import { colors, font, serif, shadow } from '../theme';

// The pills of the app: aubergine, white, anise
const TAG_COLORS = [
    { background: colors.aubergine, color: colors.white },
    { background: colors.white, color: colors.ink },
    { background: colors.anise, color: colors.forestDeep },
];

export const Intro = () => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const title = spring({ frame, fps, config: { damping: 16 } });
    const text = spring({ frame: frame - 10, fps, config: { damping: 200 } });
    const tags = spring({ frame: frame - 24, fps, config: { damping: 200 } });

    return (
        <Background>
            <div style={{ position: 'absolute', left: 140, top: 240, width: 1060 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 26, opacity: title, transform: `translateY(${interpolate(title, [0, 1], [30, 0])}px)` }}>
                    <span style={{ width: 120, height: 120, borderRadius: '50%', background: colors.white, color: colors.forest, display: 'grid', placeItems: 'center', boxShadow: shadow }}>
                        <Hop size={78} strokeWidth={1.6} />
                    </span>
                    <span style={{ fontFamily: serif, fontSize: 138, fontWeight: 800, letterSpacing: -5, color: colors.white, whiteSpace: 'nowrap' }}>{script.intro.title}</span>
                </div>
                <div style={{ fontFamily: serif, fontSize: 46, fontWeight: 500, marginTop: 34, color: colors.white, lineHeight: 1.3, maxWidth: 840, opacity: text, transform: `translateY(${interpolate(text, [0, 1], [20, 0])}px)` }}>
                    {script.intro.tagline}
                </div>
                <div style={{ display: 'flex', gap: 14, marginTop: 46, opacity: tags }}>
                    {script.intro.tags.map((tag, i) => (
                        <span key={tag} style={{ fontFamily: font, fontSize: 30, fontWeight: 700, padding: '12px 30px', borderRadius: 999, boxShadow: shadow, ...TAG_COLORS[i % TAG_COLORS.length] }}>
                            {tag}
                        </span>
                    ))}
                </div>
            </div>
            <div style={{ position: 'absolute', right: 140, top: 196 }}>
                <Plate plate="hydrangea-macrophylla" caption="Hydrangea hortensia" width={500} />
            </div>
            <div style={{ position: 'absolute', left: 140, bottom: 70, padding: '10px 22px', borderRadius: 999, background: colors.veil, fontFamily: font, fontSize: 22, fontWeight: 700, letterSpacing: 4, textTransform: 'uppercase', color: colors.white, opacity: tags }}>
                {script.intro.event}
            </div>
        </Background>
    );
};
