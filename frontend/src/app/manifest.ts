import type { MetadataRoute } from "next";
import { BRAND } from "@/lib/brand";

/**
 * Lets staff install PatoPro on a phone or computer. Android builds the
 * splash screen from the name, background colour and the 512 px icon.
 */
export default function manifest(): MetadataRoute.Manifest {
  return {
    name: `${BRAND.company} ${BRAND.product}`,
    short_name: BRAND.product,
    description: "Sales, stock, accounting, HR and online orders for every kind of business.",
    id: "/admin",
    start_url: "/admin",
    scope: "/",
    display: "standalone",
    orientation: "any",
    background_color: "#ffffff",
    theme_color: BRAND.blue,
    lang: "sw",
    categories: ["business", "finance", "productivity"],
    icons: [
      { src: "/icons/icon-192.png", sizes: "192x192", type: "image/png", purpose: "any" },
      { src: "/icons/icon-512.png", sizes: "512x512", type: "image/png", purpose: "any" },
      { src: "/icons/maskable-512.png", sizes: "512x512", type: "image/png", purpose: "maskable" },
      { src: "/icons/patopro-mark.svg", sizes: "any", type: "image/svg+xml" },
    ],
  };
}
