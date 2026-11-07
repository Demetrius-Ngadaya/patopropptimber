import type { NextConfig } from "next";

const securityHeaders = [
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "X-Frame-Options", value: "DENY" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  // camera=self lets the POS scan barcodes with the device camera
  { key: "Permissions-Policy", value: "camera=(self), microphone=(), geolocation=(self)" },
  { key: "Strict-Transport-Security", value: "max-age=31536000; includeSubDomains" },
];

const nextConfig: NextConfig = {
  poweredByHeader: false,
  reactStrictMode: true,
  output: "standalone",
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
  async redirects() {
    // Short, easy-to-say addresses for the mobile app download page.
    return [
      // www.pptimber.co.tz → pptimber.co.tz, so logins and cookies always live on one address.
      { source: "/:path*", has: [{ type: "host", value: "www\\.(?<domain>.+)" }], destination: "https://:domain/:path*", permanent: true },
      { source: "/pakua", destination: "/app", permanent: false },
      { source: "/download", destination: "/app", permanent: false },
    ];
  },
  async headers() {
    return [{ source: "/:path*", headers: securityHeaders }];
  },
};

export default nextConfig;
