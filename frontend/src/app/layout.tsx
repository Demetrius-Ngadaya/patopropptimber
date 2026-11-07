import type { Metadata, Viewport } from "next";
import { cookies } from "next/headers";
import "@fontsource-variable/onest";
import "./globals.css";
import { Providers } from "@/components/providers/Providers";
import { DEFAULT_LOCALE, LOCALE_COOKIE, isLocale } from "@/lib/i18n/config";
import { THEME_COOKIE, themeBootScript, type ThemePreference } from "@/lib/theme/config";
import { BRAND_STYLE_ID, brandCss, colorsFromSettings } from "@/lib/theme/palette";
import { apiUrl } from "@/lib/server/backend";
import { BRAND } from "@/lib/brand";

/** Admin-chosen colours, painted on the server so the first frame is already right. */
async function brandStyle(): Promise<string> {
  try {
    const res = await fetch(apiUrl("public/settings"), {
      headers: { Accept: "application/json" },
      next: { revalidate: 60, tags: ["public-settings"] },
      signal: AbortSignal.timeout(2000),
    });
    if (!res.ok) return "";
    const body = (await res.json()) as { data?: Record<string, unknown> };
    return brandCss(colorsFromSettings(body.data));
  } catch {
    return ""; // API down: fall back to the built-in colours
  }
}

export const metadata: Metadata = {
  title: { default: BRAND.product, template: `${BRAND.product} — %s` },
  applicationName: BRAND.product,
  description: "Sales, stock, accounting, HR and online orders for shops, wholesalers, pharmacies, butcheries and hardware stores.",
  authors: [{ name: BRAND.companyLegal }],
  creator: BRAND.companyLegal,
  icons: { apple: "/icons/apple-touch-icon.png" },
  appleWebApp: { capable: true, title: BRAND.product, statusBarStyle: "default" },
};

export const viewport: Viewport = {
  themeColor: "#13213b",
  width: "device-width",
  initialScale: 1,
};

export default async function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  const store = await cookies();
  const cookieLocale = store.get(LOCALE_COOKIE)?.value;
  const locale = isLocale(cookieLocale) ? cookieLocale : DEFAULT_LOCALE;
  const cookieTheme = store.get(THEME_COOKIE)?.value;
  const theme: ThemePreference = cookieTheme === "light" || cookieTheme === "dark" ? cookieTheme : "system";
  const brand = await brandStyle();

  return (
    <html lang={locale} className={theme === "dark" ? "dark" : undefined} suppressHydrationWarning>
      <head>
        {/* Applies a dark device theme before first paint (no white flash). */}
        <script dangerouslySetInnerHTML={{ __html: themeBootScript }} />
        {/* Generated from validated #rrggbb values only (see palette.ts). */}
        <style id={BRAND_STYLE_ID} dangerouslySetInnerHTML={{ __html: brand }} />
      </head>
      <body className="min-h-dvh">
        <Providers locale={locale} theme={theme}>{children}</Providers>
      </body>
    </html>
  );
}
