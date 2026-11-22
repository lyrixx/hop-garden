import { loadFont as loadDmSans } from '@remotion/google-fonts/DMSans';
import { loadFont as loadLiterata } from '@remotion/google-fonts/Literata';

// The garden journal of the app: white rounded cards on a deep green ground of painted leaves.
// Keep in sync with assets/styles/app.css
export const colors = {
    forest: '#0b5b3a',
    forestLight: '#0f6b45',
    forestDeep: '#073d27',
    forestSoft: '#dcefe3',
    anise: '#b5d35a',
    aniseSoft: '#eef4c6',
    olive: '#4f5c0c',
    cerulean: '#0079a8',
    ceruleanSoft: '#ddeff7',
    aubergine: '#5b2a6e',
    magenta: '#b8195f',
    crimson: '#8a1538',
    pink: '#f6c9d6',
    pinkSoft: '#fcebf0',
    cream: '#f4f1ec',
    creamDeep: '#e6e0d6',
    ink: '#1d2a23',
    muted: '#56625b',
    white: '#ffffff',
    // Behind a text set bare on the ground, where it may fall on a light leaf
    veil: 'rgba(4, 38, 24, 0.66)',
    textSoft: 'rgba(255, 255, 255, 0.86)',
};

export const shadow = '0 34px 70px -34px rgba(2, 30, 18, 0.8)';

const literata = loadLiterata('normal', { weights: ['500', '600', '700', '800'], subsets: ['latin'] });
loadLiterata('italic', { weights: ['500', '600'], subsets: ['latin'] });
const dmSans = loadDmSans('normal', { weights: ['400', '500', '600', '700', '800'], subsets: ['latin'] });

export const serif = `${literata.fontFamily}, Georgia, serif`;
export const font = `${dmSans.fontFamily}, system-ui, sans-serif`;
export const mono = 'ui-monospace, "SFMono-Regular", Menlo, Consolas, "DejaVu Sans Mono", monospace';

export const FPS = 30;
export const WIDTH = 1920;
export const HEIGHT = 1080;
