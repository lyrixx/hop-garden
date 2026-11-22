import { colors, font, shadow } from '../theme';

export const BROWSER = { x: 192, y: 160, width: 1536, chrome: 40 };

export const BrowserWindow = ({ children, url = 'hop-garden.test' }: { children: React.ReactNode; url?: string }) => (
    <div style={{ position: 'absolute', left: BROWSER.x, top: BROWSER.y, width: BROWSER.width, borderRadius: 24, overflow: 'hidden', background: colors.white, boxShadow: shadow }}>
        <div style={{ height: BROWSER.chrome, background: colors.white, display: 'flex', alignItems: 'center', gap: 8, padding: '0 18px' }}>
            {[colors.magenta, colors.anise, colors.cerulean].map((color) => (
                <span key={color} style={{ width: 12, height: 12, borderRadius: '50%', background: color }} />
            ))}
            <div style={{ marginLeft: 24, flex: 1, maxWidth: 480, height: 26, borderRadius: 999, background: colors.cream, display: 'flex', alignItems: 'center', padding: '0 14px', fontFamily: font, fontWeight: 600, fontSize: 15, color: colors.muted }}>
                {url}
            </div>
        </div>
        <div style={{ width: BROWSER.width, height: (BROWSER.width * 9) / 16, position: 'relative' }}>{children}</div>
    </div>
);
