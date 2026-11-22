import { interpolate, spring, useCurrentFrame, useVideoConfig } from 'remotion';
import { colors, font, serif } from '../theme';

// The step reads like a figure number on a plate, in an aubergine pill
export const CaptionBar = ({ step, title, subtitle }: { step?: string; title: string; subtitle?: string }) => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const progress = spring({ frame, fps, config: { damping: 200 } });

    return (
        <div
            style={{
                position: 'absolute',
                left: 192,
                top: 30,
                display: 'flex',
                alignItems: 'center',
                gap: 22,
                opacity: progress,
                transform: `translateY(${interpolate(progress, [0, 1], [-14, 0])}px)`,
            }}
        >
            {step && (
                <span style={{ minWidth: 104, height: 64, padding: '0 20px', borderRadius: 999, background: colors.aubergine, color: colors.white, display: 'grid', placeItems: 'center', fontFamily: serif, fontStyle: 'italic', fontWeight: 600, fontSize: 30 }}>
                    fig. {step}
                </span>
            )}
            <div>
                <div style={{ fontFamily: serif, fontSize: 44, fontWeight: 800, letterSpacing: -0.8, lineHeight: 1.05, color: colors.white }}>{title}</div>
                {subtitle && <div style={{ fontFamily: font, fontSize: 24, color: colors.textSoft, marginTop: 6, fontWeight: 600 }}>{subtitle}</div>}
            </div>
        </div>
    );
};
