import { Crown, HeartCrack, Repeat, Sparkles, TrendingDown, UserRound, type LucideIcon } from "lucide-react";

export type Segment = "vip" | "frequent" | "new" | "declining" | "lost" | "occasional";

export const SEGMENTS: Record<Segment, { label: string; hint: string; tone: "navy" | "green" | "blue" | "saffron" | "red" | "neutral"; icon: LucideIcon }> = {
  vip: { label: "VIP", hint: "Spend the most in the last 12 months", tone: "navy", icon: Crown },
  frequent: { label: "Frequent", hint: "Buy often (last 90 days)", tone: "green", icon: Repeat },
  new: { label: "New", hint: "First purchase recently", tone: "blue", icon: Sparkles },
  declining: { label: "Coming less", hint: "Buying less often than before", tone: "saffron", icon: TrendingDown },
  lost: { label: "Stopped coming", hint: "No purchase for a long time", tone: "red", icon: HeartCrack },
  occasional: { label: "Occasional", hint: "Buy now and then", tone: "neutral", icon: UserRound },
};
export const SEGMENT_KEYS = Object.keys(SEGMENTS) as Segment[];
