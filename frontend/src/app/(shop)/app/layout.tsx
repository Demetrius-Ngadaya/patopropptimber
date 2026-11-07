import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Download the app",
  description: "Download the PatoPro Android app: sell, take orders and check stock even without internet.",
};

export default function AppPageLayout({ children }: { children: React.ReactNode }) {
  return children;
}
