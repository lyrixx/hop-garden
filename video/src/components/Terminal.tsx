import { interpolate, useCurrentFrame, useVideoConfig } from 'remotion';
import { colors, mono, shadow } from '../theme';

/**
 * Types `command` from frame 0, "presses enter" at `enterAt` (frames), then prints `output`.
 */
export const Terminal = ({ command, output, enterAt, style }: { command: string; output?: string; enterAt: number; style?: React.CSSProperties }) => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const typing = Math.min(command.length, Math.floor(interpolate(frame, [0, Math.max(1, enterAt - fps * 0.3)], [0, command.length], { extrapolateRight: 'clamp' })));
    const cursorOn = Math.floor(frame / (fps / 2)) % 2 === 0;
    const showOutput = output !== undefined && frame > enterAt + fps * 0.35;

    return (
        <div
            style={{
                background: colors.forestDeep,
                borderRadius: 24,
                boxShadow: `inset 0 0 0 1px rgba(255, 255, 255, 0.12), ${shadow}`,
                fontFamily: mono,
                fontSize: 26,
                color: colors.white,
                padding: '20px 28px 24px',
                ...style,
            }}
        >
            <div style={{ display: 'flex', gap: 8, marginBottom: 14 }}>
                {[colors.magenta, colors.anise, colors.cerulean].map((color) => (
                    <span key={color} style={{ width: 12, height: 12, borderRadius: '50%', background: color }} />
                ))}
            </div>
            <div style={{ whiteSpace: 'pre-wrap' }}>
                <span style={{ color: colors.anise }}>$ </span>
                {command.slice(0, typing)}
                {frame < enterAt && <span style={{ opacity: cursorOn ? 1 : 0 }}>▋</span>}
            </div>
            {showOutput && <div style={{ marginTop: 10, color: colors.anise, whiteSpace: 'pre-wrap' }}>{output}</div>}
        </div>
    );
};
