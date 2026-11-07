"use client";

import { useEffect } from "react";
import { usePublicSettings } from "@/lib/public-settings";
import { BRAND_STYLE_ID, brandCss, colorsFromSettings } from "@/lib/theme/palette";

/**
 * The server paints the admin-chosen colours on first load (no flash). This
 * keeps them current afterwards, e.g. straight after an admin saves new ones.
 */
export function BrandColorSync() {
  const { settings } = usePublicSettings();

  useEffect(() => {
    if (!settings) return;
    const css = brandCss(colorsFromSettings(settings as unknown as Record<string, unknown>));
    let el = document.getElementById(BRAND_STYLE_ID) as HTMLStyleElement | null;
    if (!el) {
      el = document.createElement("style");
      el.id = BRAND_STYLE_ID;
      document.head.appendChild(el);
    }
    if (el.textContent !== css) el.textContent = css;
  }, [settings]);

  return null;
}
