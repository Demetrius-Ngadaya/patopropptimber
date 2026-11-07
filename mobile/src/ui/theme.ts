import { useColorScheme } from 'react-native';
import { usePrefs } from '../lib/prefs';

/** PatoPro colours (frontend/src/lib/brand.ts): SHEZONI blue, growth green. */
const light = {
  scheme: 'light' as 'light' | 'dark',
  bg: '#F4F6FA',
  surface: '#FFFFFF',
  surfaceAlt: '#EEF2F8',
  surfacePressed: '#E6EBF3',
  border: '#E1E7F0',
  borderStrong: '#C9D3E2',
  text: '#0E1726',
  textMuted: '#5B6B82',
  textFaint: '#8A98AD',
  primary: '#0B3D91',
  primaryPressed: '#082E6E',
  primarySoft: '#E5EDFA',
  onPrimary: '#FFFFFF',
  accent: '#16A34A',
  accentSoft: '#E3F6EA',
  warning: '#B45309',
  warningSoft: '#FDF1DE',
  danger: '#C62828',
  dangerSoft: '#FCE8E8',
  info: '#0369A1',
  infoSoft: '#E0F0FA',
  overlay: 'rgba(8, 15, 30, 0.45)',
  shadow: '#0B1A33',
  heroFrom: '#0B3D91',
  heroTo: '#11509F',
};

const dark: typeof light = {
  scheme: 'dark',
  bg: '#0A101C',
  surface: '#111A2B',
  surfaceAlt: '#18233A',
  surfacePressed: '#1F2C46',
  border: '#22304A',
  borderStrong: '#2F3F5E',
  text: '#E8EDF6',
  textMuted: '#9AA9C0',
  textFaint: '#6D7D96',
  primary: '#5B8DEF',
  primaryPressed: '#4778D8',
  primarySoft: '#16264A',
  onPrimary: '#FFFFFF',
  accent: '#4ADE80',
  accentSoft: '#12301F',
  warning: '#F59E0B',
  warningSoft: '#33240C',
  danger: '#F87171',
  dangerSoft: '#3A1717',
  info: '#38BDF8',
  infoSoft: '#0E2A3A',
  overlay: 'rgba(0, 0, 0, 0.6)',
  shadow: '#000000',
  heroFrom: '#0E2F6B',
  heroTo: '#123E85',
};

export type Colors = typeof light;

export const space = { xs: 4, sm: 8, md: 12, lg: 16, xl: 20, xxl: 28, xxxl: 40 } as const;
export const radius = { sm: 8, md: 12, lg: 16, xl: 22, pill: 999 } as const;

export const type = {
  display: { fontSize: 30, lineHeight: 36, fontWeight: '800' as const, letterSpacing: -0.6 },
  title: { fontSize: 22, lineHeight: 28, fontWeight: '800' as const, letterSpacing: -0.3 },
  heading: { fontSize: 17, lineHeight: 22, fontWeight: '700' as const },
  body: { fontSize: 15, lineHeight: 21, fontWeight: '400' as const },
  bodyStrong: { fontSize: 15, lineHeight: 21, fontWeight: '600' as const },
  label: { fontSize: 13, lineHeight: 17, fontWeight: '600' as const },
  caption: { fontSize: 12, lineHeight: 16, fontWeight: '500' as const },
  tiny: { fontSize: 11, lineHeight: 14, fontWeight: '600' as const, letterSpacing: 0.4 },
};

export function colorsFor(scheme: 'light' | 'dark'): Colors {
  return scheme === 'dark' ? dark : light;
}

export function useColors(): Colors {
  const system = useColorScheme();
  const pref = usePrefs((s) => s.theme);
  const scheme = pref === 'system' ? (system === 'dark' ? 'dark' : 'light') : pref;
  return colorsFor(scheme);
}

export function shadow(c: Colors, level: 1 | 2 | 3 = 1) {
  const map = { 1: [2, 6, 0.06, 2], 2: [6, 16, 0.1, 5], 3: [12, 28, 0.16, 10] } as const;
  const [y, r, o, e] = map[level];
  return {
    shadowColor: c.shadow,
    shadowOffset: { width: 0, height: y },
    shadowRadius: r,
    shadowOpacity: c.scheme === 'dark' ? o * 2 : o,
    elevation: e,
  };
}
