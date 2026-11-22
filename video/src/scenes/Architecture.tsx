import { interpolate, spring, useCurrentFrame, useVideoConfig } from 'remotion';
import { Background } from '../components/Background';
import { script } from '../script';
import { colors, font, serif, shadow } from '../theme';

type Box = { x: number; y: number; w: number; h: number };

const NODES: Array<Box & { id: string; title: string; subtitle?: string; group?: boolean }> = [
    { id: 'frankenphp', x: 420, y: 190, w: 540, h: 650, title: 'FrankenPHP', group: true },
    { id: 'worker', x: 1090, y: 190, w: 470, h: 650, title: 'Messenger worker', group: true },
    { id: 'browser', x: 70, y: 430, w: 260, h: 150, title: 'Browser', subtitle: 'Twig + Stimulus' },
    { id: 'web', x: 460, y: 260, w: 460, h: 120, title: 'Symfony web UI' },
    { id: 'hub', x: 460, y: 460, w: 460, h: 120, title: 'Mercure hub' },
    { id: 'mcp', x: 460, y: 660, w: 460, h: 140, title: 'MCP server', subtitle: '/_mcp/agent · /_mcp' },
    { id: 'agent', x: 1130, y: 260, w: 390, h: 120, title: 'Symfony AI agent' },
    { id: 'client', x: 1130, y: 460, w: 390, h: 120, title: 'MCP client' },
    { id: 'scheduler', x: 1130, y: 660, w: 390, h: 140, title: 'Scheduler', subtitle: 'reminders at 7:00' },
    { id: 'llm', x: 1640, y: 260, w: 220, h: 120, title: 'LLM', subtitle: 'Claude · GPT' },
    { id: 'apis', x: 420, y: 900, w: 540, h: 110, title: 'Trefle · Open-Meteo' },
];

// A diagonal edge has its label along the line (labelOffset is then relative to the line)
const EDGES: Array<{ from: [number, number]; to: [number, number]; label: string; highlight?: boolean; labelOffset?: [number, number] }> = [
    { from: [330, 490], to: [460, 330], label: 'POST /chat', labelOffset: [0, -16] },
    { from: [920, 320], to: [1130, 320], label: 'Messenger' },
    { from: [1520, 320], to: [1640, 320], label: '' },
    { from: [1325, 380], to: [1325, 460], label: 'tools', labelOffset: [45, 0] },
    { from: [1130, 540], to: [920, 720], label: 'MCP over HTTP', highlight: true, labelOffset: [20, -22] },
    { from: [690, 800], to: [690, 900], label: '' },
    { from: [1130, 350], to: [920, 500], label: 'tokens · tool calls · cards', labelOffset: [-6, -16] },
    { from: [460, 530], to: [330, 530], label: 'SSE', labelOffset: [0, 28] },
];

// Like the app: white cards for the application, tinted ones for the outside world, pink for MCP
const ACCENTS: Record<string, string> = { browser: colors.aniseSoft, llm: colors.ceruleanSoft, apis: colors.anise, mcp: colors.pinkSoft };

const NODES_SECONDS = 1.4;
const EDGE_SECONDS = 0.9;

export const Architecture = () => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const title = spring({ frame, fps, config: { damping: 200 } });

    return (
        <Background>
            <div style={{ position: 'absolute', top: 40, width: '100%', textAlign: 'center', color: colors.white, opacity: title }}>
                <div style={{ fontFamily: serif, fontSize: 56, fontWeight: 800, letterSpacing: -1.5 }}>{script.architecture.title}</div>
                <div style={{ fontFamily: font, fontSize: 26, fontWeight: 600, color: colors.textSoft, marginTop: 6 }}>{script.architecture.subtitle}</div>
            </div>

            {NODES.map((node, i) => {
                const progress = spring({ frame: frame - i * 3, fps, config: { damping: 200 } });

                return (
                    <div
                        key={node.id}
                        style={{
                            position: 'absolute',
                            left: node.x,
                            top: node.y,
                            width: node.w,
                            height: node.h,
                            borderRadius: node.group ? 32 : 24,
                            fontFamily: font,
                            opacity: progress,
                            display: 'flex',
                            flexDirection: 'column',
                            alignItems: 'center',
                            justifyContent: node.group ? 'flex-start' : 'center',
                            paddingTop: node.group ? 14 : 0,
                            background: node.group ? 'transparent' : ACCENTS[node.id] ?? colors.white,
                            border: node.group ? '2px dashed rgba(255, 255, 255, 0.55)' : 'none',
                            boxShadow: node.group ? 'none' : shadow,
                            color: node.group ? colors.textSoft : colors.ink,
                        }}
                    >
                        <div style={{ fontFamily: node.group ? font : serif, fontSize: node.group ? 22 : 'apis' === node.id ? 30 : 32, fontWeight: 700, letterSpacing: node.group ? 3 : -0.3, textTransform: node.group ? 'uppercase' : 'none' }}>{node.title}</div>
                        {node.subtitle && <div style={{ fontSize: 22, color: colors.muted, marginTop: 4 }}>{node.subtitle}</div>}
                    </div>
                );
            })}

            <svg width={1920} height={1080} style={{ position: 'absolute', inset: 0 }}>
                <defs>
                    <marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                        <path d="M0,0 L10,5 L0,10 z" fill={colors.white} />
                    </marker>
                    <marker id="arrow-highlight" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                        <path d="M0,0 L10,5 L0,10 z" fill={colors.pink} />
                    </marker>
                </defs>
                {EDGES.map((edge, i) => {
                    const start = (NODES_SECONDS + i * EDGE_SECONDS) * fps;
                    const progress = interpolate(frame, [start, start + EDGE_SECONDS * fps * 0.8], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
                    const length = Math.hypot(edge.to[0] - edge.from[0], edge.to[1] - edge.from[1]);
                    const [dx, dy] = edge.labelOffset ?? [0, -18];
                    const color = edge.highlight ? colors.pink : colors.white;
                    const diagonal = edge.from[0] !== edge.to[0] && edge.from[1] !== edge.to[1];
                    // Kept readable: never upside down
                    let angle = (Math.atan2(edge.to[1] - edge.from[1], edge.to[0] - edge.from[0]) * 180) / Math.PI;
                    if (angle > 90) angle -= 180;
                    if (angle < -90) angle += 180;
                    const labelX = (edge.from[0] + edge.to[0]) / 2;
                    const labelY = (edge.from[1] + edge.to[1]) / 2;

                    return (
                        <g key={edge.label + i} opacity={progress > 0 ? 1 : 0}>
                            <line
                                x1={edge.from[0]}
                                y1={edge.from[1]}
                                x2={edge.to[0]}
                                y2={edge.to[1]}
                                stroke={color}
                                strokeWidth={edge.highlight ? 7 : 4}
                                strokeLinecap="round"
                                strokeDasharray={length}
                                strokeDashoffset={length * (1 - progress)}
                                markerEnd={progress > 0.95 ? `url(#${edge.highlight ? 'arrow-highlight' : 'arrow'})` : undefined}
                            />
                            {edge.label && (
                                <text
                                    x={diagonal ? labelX : labelX + dx}
                                    y={diagonal ? labelY : labelY + dy}
                                    transform={diagonal ? `rotate(${angle} ${labelX} ${labelY}) translate(${dx} ${dy})` : undefined}
                                    textAnchor="middle"
                                    fontFamily={font}
                                    fontSize={edge.highlight ? 28 : 22}
                                    fontWeight={700}
                                    fill={color}
                                    // A halo of the ground keeps the label readable where it crosses a card or a light leaf
                                    stroke={colors.forest}
                                    strokeWidth={6}
                                    strokeLinejoin="round"
                                    paintOrder="stroke"
                                    opacity={progress}
                                >
                                    {edge.label}
                                </text>
                            )}
                        </g>
                    );
                })}
            </svg>
        </Background>
    );
};
