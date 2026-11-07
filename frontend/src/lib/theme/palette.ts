// Plain module (no "use client"): used by the server layout and in the browser.

/**
 * Admin-chosen colours. Each setting is one hex colour; the shades the design
 * uses (brand-50 … brand-800 and so on) are mixed from it for both the light
 * and the dark theme, so any colour an admin picks still gives readable
 * backgrounds, borders and text.
 */
export type BrandColors = { primary?: string | null; accent?: string | null; sidebar?: string | null };

/** The built-in look (same values as globals.css). */
export const DEFAULT_COLORS = { primary: "#157a55", accent: "#e3a015", sidebar: "#13213b" } as const;

/** Ready-made combinations offered on the Appearance screen. */
export const COLOR_PRESETS: { name: string; primary: string; accent: string; sidebar: string }[] = [
  { name: "PatoPro green", ...DEFAULT_COLORS },
  { name: "Ocean blue", primary: "#1d5fbf", accent: "#f2a516", sidebar: "#0f1e3d" },
  { name: "Royal purple", primary: "#6b3fc4", accent: "#e8a33d", sidebar: "#1e1538" },
  { name: "Sunset orange", primary: "#c4501a", accent: "#2b8a7e", sidebar: "#2a1a14" },
  { name: "Ruby red", primary: "#b3243b", accent: "#d9a21b", sidebar: "#2b1218" },
  { name: "Teal", primary: "#0f7c86", accent: "#e98a2c", sidebar: "#0d2328" },
  { name: "Charcoal", primary: "#3a4a5f", accent: "#d97a1e", sidebar: "#161b22" },
];

const HEX = /^#[0-9a-f]{6}$/i;
const WHITE = "#ffffff";
const BLACK = "#000000";
const DARK_SURFACE = "#0e182c"; // dark theme page background

export function isHex(value: unknown): value is string {
  return typeof value === "string" && HEX.test(value);
}

function rgb(hex: string): [number, number, number] {
  const n = parseInt(hex.slice(1), 16);
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

/** `amount` of `other` mixed into `base` (0 = base, 1 = other). */
function mix(base: string, other: string, amount: number): string {
  const a = rgb(base);
  const b = rgb(other);
  return "#" + a.map((c, i) => Math.round(c + (b[i] - c) * amount).toString(16).padStart(2, "0")).join("");
}

function luminance(hex: string): number {
  const [r, g, b] = rgb(hex).map((c) => {
    const s = c / 255;
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG contrast ratio between two colours (1 – 21). */
export function contrast(a: string, b: string): number {
  const [x, y] = [luminance(a), luminance(b)].sort((p, q) => q - p);
  return (x + 0.05) / (y + 0.05);
}

type Shades = Record<string, string>;

function primaryShades(c: string, dark: boolean): Shades {
  return dark
    ? { 50: mix(c, DARK_SURFACE, 0.82), 100: mix(c, DARK_SURFACE, 0.72), 200: mix(c, DARK_SURFACE, 0.5), 500: mix(c, WHITE, 0.12), 600: mix(c, WHITE, 0.06), 700: mix(c, WHITE, 0.35), 800: mix(c, WHITE, 0.6) }
    : { 50: mix(c, WHITE, 0.9), 100: mix(c, WHITE, 0.8), 200: mix(c, WHITE, 0.6), 500: mix(c, WHITE, 0.12), 600: c, 700: mix(c, BLACK, 0.2), 800: mix(c, BLACK, 0.38) };
}

function accentShades(c: string, dark: boolean): Shades {
  return dark
    ? { 50: mix(c, DARK_SURFACE, 0.85), 100: mix(c, DARK_SURFACE, 0.78), 300: mix(c, DARK_SURFACE, 0.4), 500: c, 600: mix(c, WHITE, 0.2), 700: mix(c, WHITE, 0.45) }
    : { 50: mix(c, WHITE, 0.9), 100: mix(c, WHITE, 0.72), 300: mix(c, WHITE, 0.3), 500: c, 600: mix(c, BLACK, 0.15), 700: mix(c, BLACK, 0.36) };
}

function sidebarShades(c: string, dark: boolean): Shades {
  return dark
    ? { 700: mix(c, WHITE, 0.17), 800: mix(c, WHITE, 0.08), 900: mix(c, BLACK, 0.25), 950: mix(c, BLACK, 0.5) }
    : { 700: mix(c, WHITE, 0.15), 800: mix(c, WHITE, 0.07), 900: c, 950: mix(c, BLACK, 0.45) };
}

function vars(colors: BrandColors, dark: boolean): string {
  const out: string[] = [];
  const add = (name: string, shades: Shades) => Object.entries(shades).forEach(([k, v]) => out.push(`--color-${name}-${k}:${v}`));
  if (isHex(colors.primary)) add("brand", primaryShades(colors.primary.toLowerCase(), dark));
  if (isHex(colors.accent)) add("saffron", accentShades(colors.accent.toLowerCase(), dark));
  if (isHex(colors.sidebar)) add("night", sidebarShades(colors.sidebar.toLowerCase(), dark));
  return out.join(";");
}

/**
 * CSS that overrides the built-in colour tokens. Selectors are deliberately
 * more specific than those in globals.css, so the result does not depend on
 * which stylesheet loads first. Returns "" when nothing is customised.
 */
export function brandCss(colors: BrandColors): string {
  const light = vars(colors, false);
  if (!light) return "";
  const dark = vars(colors, true);
  return `html:root:not(.dark),html:root .keep-light{${light}}html:root.dark{${dark}}html:root.dark .keep-light{${light}}@media print{html:root.dark,html:root:not(.dark){${light}}}`;
}

/** Reads the three public settings. */
export function colorsFromSettings(settings: Record<string, unknown> | null | undefined): BrandColors {
  return { primary: settings?.theme_primary as string | null, accent: settings?.theme_accent as string | null, sidebar: settings?.theme_sidebar as string | null };
}

export const BRAND_STYLE_ID = "mz-brand-colors";
