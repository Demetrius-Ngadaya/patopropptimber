import { Mail, MessageCircle, MessageSquareText, type LucideIcon } from "lucide-react";
import { usePublicSettings } from "@/lib/public-settings";

export type Channel = "sms" | "whatsapp" | "email";

export const CHANNELS: { value: Channel; label: string; icon: LucideIcon; hint: string }[] = [
  { value: "sms", label: "SMS", icon: MessageSquareText, hint: "Short summary" },
  { value: "whatsapp", label: "WhatsApp", icon: MessageCircle, hint: "Fuller message" },
  { value: "email", label: "Email", icon: Mail, hint: "Full details + PDF" },
];

/** The ways of sending the admin has made available (Settings → Messages). */
export function useEnabledChannels(): { channels: Channel[]; loading: boolean } {
  const { settings, isLoading } = usePublicSettings();
  const list = (settings?.messaging_channels ?? ["sms", "email"]) as string[];
  return { channels: CHANNELS.map((c) => c.value).filter((c) => list.includes(c)), loading: isLoading };
}

/** Text for the server's per-channel result, e.g. "SMS ✓ · Email ✓". */
export type DeliveryResult = Partial<Record<Channel, { recipients: number; sent: boolean }>>;
