"use client";

import { AlertTriangle, CheckCircle2, Moon, Sun } from "lucide-react";
import { cn, date, dateTime, money, toNumber } from "@/lib/format";
import { useT } from "@/lib/i18n";
import { Panel } from "@/components/ui";
import { ProviderDot } from "./AgencyNav";
import type { AgencyDay } from "./types";

export function Difference({ value }: { value: number }) {
  const t = useT();
  const even = Math.abs(value) < 0.005;
  return (
    <p className={cn("mt-3 flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium", even ? "bg-brand-50 text-brand-800" : value < 0 ? "bg-ember-50 text-ember-700" : "bg-saffron-50 text-saffron-800")}>
      {even ? <CheckCircle2 className="size-4" /> : <AlertTriangle className="size-4" />}
      {even ? t("Everything balances.") : value < 0 ? t("Shortage of {amount}", { amount: money(-value) }) : t("Excess of {amount}", { amount: money(value) })}
    </p>
  );
}

export function DaySummary({ day }: { day: AgencyDay }) {
  const t = useT();
  const closed = day.status === "closed";
  return (
    <Panel padded={false} title={<span className="flex items-center gap-2">{closed ? <Moon className="size-5" /> : <Sun className="size-5 text-saffron-600" />} {closed ? t("Closed: {date}", { date: date(day.business_date) }) : t("Open since this morning: {date}", { date: date(day.business_date) })}</span>}>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-ink-50/60 text-left text-[12.5px] text-ink-600">
            <tr><th className="px-4 py-2 font-semibold">{t("Line")}</th><th className="px-4 py-2 text-right font-semibold">{t("Morning")}</th><th className="px-4 py-2 text-right font-semibold">{t("Evening")}</th><th className="px-4 py-2 text-right font-semibold">{t("Difference")}</th></tr>
          </thead>
          <tbody>
            {day.balances.map((b) => {
              const change = b.closing_float === null ? null : toNumber(b.closing_float) - toNumber(b.opening_float);
              return (
                <tr key={b.id} className="border-t border-ink-100">
                  <td className="px-4 py-2"><span className="flex items-center gap-2"><ProviderDot provider={b.account?.provider ?? "other"} />{b.account?.name}</span></td>
                  <td className="num px-4 py-2 text-right">{money(b.opening_float)}</td>
                  <td className="num px-4 py-2 text-right">{b.closing_float === null ? "—" : money(b.closing_float)}</td>
                  <td className={cn("num px-4 py-2 text-right", change !== null && change < 0 && "text-ember-600", change !== null && change > 0 && "text-brand-700")}>{change === null ? "" : `${change > 0 ? "+" : ""}${money(change)}`}</td>
                </tr>
              );
            })}
            <tr className="border-t border-ink-100">
              <td className="px-4 py-2 font-medium">{t("Cash in hand")}</td>
              <td className="num px-4 py-2 text-right">{money(day.opening_cash)}</td>
              <td className="num px-4 py-2 text-right">{closed ? money(day.closing_cash) : "—"}</td>
              <td className="num px-4 py-2 text-right">{closed ? money(toNumber(day.closing_cash) - toNumber(day.opening_cash)) : ""}</td>
            </tr>
            <tr className="border-t-2 border-ink-200 font-semibold">
              <td className="px-4 py-2">{t("Total")}</td>
              <td className="num px-4 py-2 text-right">{money(day.opening_total)}</td>
              <td className="num px-4 py-2 text-right">{closed ? money(day.closing_total) : "—"}</td>
              <td className="num px-4 py-2 text-right">{closed ? money(toNumber(day.closing_total) - day.opening_total) : ""}</td>
            </tr>
          </tbody>
        </table>
      </div>
      {closed && (
        <div className="grid gap-3 border-t border-ink-100 p-4 sm:grid-cols-3">
          <div className="text-sm"><p className="text-ink-500">{t("Capital added")}</p><p className="num font-medium">{money(day.capital_added)}</p></div>
          <div className="text-sm"><p className="text-ink-500">{t("Capital taken out")}</p><p className="num font-medium">{money(day.capital_withdrawn)}</p></div>
          <Difference value={day.difference ?? 0} />
        </div>
      )}
      <p className="border-t border-ink-100 px-4 py-2 text-xs text-ink-500">
        {t("Opened by {name} at {time}", { name: day.opener?.name ?? "—", time: dateTime(day.opened_at) })}
        {closed && ` · ${t("Closed by {name} at {time}", { name: day.closer?.name ?? "—", time: dateTime(day.closed_at) })}`}
        {day.opening_note && ` · ${day.opening_note}`}{day.closing_note && ` · ${day.closing_note}`}
      </p>
    </Panel>
  );
}
