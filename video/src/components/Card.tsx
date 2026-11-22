import { Img, staticFile } from 'remotion';
import { colors, shadow } from '../theme';

/**
 * A white rounded card, as in the app; the main one of a scene is crowned with painted leaves rising from behind it.
 */
export const Card = ({ children, crown = false, radius = 32, style }: { children: React.ReactNode; crown?: boolean; radius?: number; style?: React.CSSProperties }) => (
    <div style={{ position: 'relative', borderRadius: radius, background: colors.white, color: colors.ink, boxShadow: shadow, ...style }}>
        {crown && <Img src={staticFile('leaves/crown.svg')} style={{ position: 'absolute', bottom: 'calc(100% - 2px)', left: 56, width: 400, height: 152, objectFit: 'contain', objectPosition: 'center bottom' }} />}
        {children}
    </div>
);
