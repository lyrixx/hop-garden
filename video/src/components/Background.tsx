import { AbsoluteFill, Img, staticFile } from 'remotion';
import { colors } from '../theme';

/**
 * The ground of the app: painted leaves on a green gradient, darker for the closing scene.
 * Keep public/leaves/ in sync with assets/images/leaves/.
 */
export const Background = ({ children, dark = false }: { children: React.ReactNode; dark?: boolean }) => (
    <AbsoluteFill style={{ background: dark ? `linear-gradient(180deg, ${colors.forestDeep}, ${colors.forest})` : `linear-gradient(180deg, ${colors.forest}, ${colors.forestLight})` }}>
        <Img src={staticFile('leaves/canopy.svg')} style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover', objectPosition: 'center bottom', opacity: dark ? 0.55 : 1 }} />
        {children}
    </AbsoluteFill>
);
