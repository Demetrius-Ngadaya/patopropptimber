"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";

import { THEME_COOKIE, type ThemePreference } from "./config";

export { THEME_COOKIE, themeBootScript, type ThemePreference } from "./config";

type ThemeContextValue = { theme: ThemePreference; resolved: "light" | "dark"; setTheme: (theme: ThemePreference) => void };
const ThemeContext = createContext<ThemeContextValue | null>(null);

function systemPrefersDark(): boolean {
  return typeof window !== "undefined" && window.matchMedia("(prefers-color-scheme: dark)").matches;
}

function apply(resolved: "light" | "dark") {
  const root = document.documentElement;
  root.classList.toggle("dark", resolved === "dark");
  root.style.colorScheme = resolved;
  document.querySelector('meta[name="theme-color"]')?.setAttribute("content", resolved === "dark" ? "#0b1222" : "#13213b");
}

/** Light / dark / follow the device. The choice is a cookie so the server renders the right theme first time. */
export function ThemeProvider({ initialTheme, children }: { initialTheme: ThemePreference; children: React.ReactNode }) {
  const [theme, setThemeState] = useState<ThemePreference>(initialTheme);
  const [systemDark, setSystemDark] = useState(() => initialTheme === "dark");

  useEffect(() => {
    const query = window.matchMedia("(prefers-color-scheme: dark)");
    setSystemDark(query.matches);
    const onChange = (e: MediaQueryListEvent) => setSystemDark(e.matches);
    query.addEventListener("change", onChange);
    return () => query.removeEventListener("change", onChange);
  }, []);

  const resolved: "light" | "dark" = theme === "system" ? (systemDark ? "dark" : "light") : theme;
  useEffect(() => {
    apply(resolved);
  }, [resolved]);

  const setTheme = useCallback((next: ThemePreference) => {
    document.cookie = `${THEME_COOKIE}=${next}; path=/; max-age=${60 * 60 * 24 * 365}; samesite=lax`;
    setThemeState(next);
    if (next === "system") setSystemDark(systemPrefersDark());
  }, []);

  const value = useMemo(() => ({ theme, resolved, setTheme }), [theme, resolved, setTheme]);
  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme(): ThemeContextValue {
  const ctx = useContext(ThemeContext);
  if (!ctx) throw new Error("useTheme must be used inside <ThemeProvider>");
  return ctx;
}
