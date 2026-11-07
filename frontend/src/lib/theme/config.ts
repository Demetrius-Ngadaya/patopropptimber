// Plain module (no "use client") so server components can read these values.
// Exported from a client module, a constant becomes a client reference on the server.

export type ThemePreference = "light" | "dark" | "system";
export const THEME_COOKIE = "mz_theme";

/**
 * Runs before first paint when the preference is "system", so a dark-mode
 * device never flashes white. Kept tiny and static (no user input).
 */
export const themeBootScript = `try{if(document.cookie.indexOf('${THEME_COOKIE}=dark')>-1||(document.cookie.indexOf('${THEME_COOKIE}=light')<0&&matchMedia('(prefers-color-scheme: dark)').matches)){document.documentElement.classList.add('dark');document.documentElement.style.colorScheme='dark'}}catch(e){}`;
