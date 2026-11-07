"use client";

import { Check } from "lucide-react";
import { cn } from "@/lib/format";
import { useT } from "@/lib/i18n";
import { CHANNELS, useEnabledChannels, type Channel } from "./channels";

/**
 * Tick boxes for how a message goes out. Only the channels the admin made
 * available are shown; each says what the recipient gets.
 */
export function ChannelPicker({ value, onChange, label, missing, disabled }: {
  value: Channel[];
  onChange: (v: Channel[]) => void;
  label?: string;
  /** Channels that cannot reach this recipient (e.g. no email address) and why. */
  missing?: Partial<Record<Channel, string>>;
  disabled?: boolean;
}) {
  const t = useT();
  const { channels } = useEnabledChannels();
  const toggle = (c: Channel) => onChange(value.includes(c) ? value.filter((x) => x !== c) : [...value, c]);

  if (!channels.length) {
    return <p className="rounded-md bg-ink-50 px-3 py-2 text-sm text-ink-600">{t("No way of sending is switched on. An administrator can choose them in Settings → Messages.")}</p>;
  }

  return (
    <fieldset>
      <legend className="mb-1.5 text-sm font-medium text-ink-700">{label ?? t("Send by")}</legend>
      <div className="grid gap-2 sm:grid-cols-3">
        {CHANNELS.filter((c) => channels.includes(c.value)).map((c) => {
          const on = value.includes(c.value);
          const why = missing?.[c.value];
          return (
            <label
              key={c.value}
              className={cn(
                "relative flex cursor-pointer items-start gap-2.5 rounded-md p-2.5 ring-1 transition-colors",
                on ? "bg-brand-50 ring-brand-500" : "ring-ink-200 hover:bg-ink-50",
                (disabled || why) && "cursor-not-allowed opacity-60",
              )}
            >
              <input type="checkbox" className="sr-only" checked={on} disabled={disabled || Boolean(why)} onChange={() => toggle(c.value)} />
              <span className={cn("grid size-8 shrink-0 place-items-center rounded-md", on ? "bg-brand-600 text-white" : "bg-ink-100 text-ink-600")}>
                {on ? <Check className="size-4" /> : <c.icon className="size-4" />}
              </span>
              <span className="min-w-0">
                <span className="block text-sm font-medium text-ink-900">{t(c.label)}</span>
                <span className="block text-xs text-ink-500">{why ?? t(c.hint)}</span>
              </span>
            </label>
          );
        })}
      </div>
    </fieldset>
  );
}
