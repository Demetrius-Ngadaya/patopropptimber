"use client";

import { useState } from "react";
import { UserRound, X } from "lucide-react";
import { useApi, useDebounced } from "@/lib/hooks";
import { money, toNumber, cn } from "@/lib/format";
import type { Customer } from "@/lib/types";
import { useT } from "@/lib/i18n";

export function CustomerPicker({ value, onChange, placeholder = "Search customer by name or phone", allowClear = true }: { value: Customer | null; onChange: (c: Customer | null) => void; placeholder?: string; allowClear?: boolean }) {
  const t = useT();
  const [term, setTerm] = useState("");
  const [open, setOpen] = useState(false);
  const debounced = useDebounced(term.trim(), 250);
  const results = useApi<{ data: Customer[] }>(debounced.length >= 2 ? "admin/lookups/customers" : null, { search: debounced });

  if (value) {
    return (
      <div className="flex items-center justify-between gap-2 rounded-[var(--radius-control)] border border-brand-200 bg-brand-50 px-3 py-2">
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold text-ink-900">{value.name}</p>
          <p className="truncate text-xs text-ink-600">
            {value.phone ?? value.code}
            {toNumber(value.balance) > 0 && <span className="text-ember-600">{t(", owes {balance}", { balance: money(value.balance) })}</span>}
          </p>
        </div>
        {allowClear && (
          <button type="button" onClick={() => onChange(null)} className="rounded p-1 text-ink-500 hover:bg-surface" aria-label={t("Remove customer")}>
            <X className="size-4" />
          </button>
        )}
      </div>
    );
  }

  return (
    <div className="relative">
      <UserRound className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" aria-hidden />
      <input
        value={term}
        onChange={(e) => {
          setTerm(e.target.value);
          setOpen(true);
        }}
        onFocus={() => setOpen(true)}
        onBlur={() => setTimeout(() => setOpen(false), 150)}
        placeholder={placeholder}
        aria-label={placeholder}
        className="h-10 w-full rounded-[var(--radius-control)] border border-ink-200 bg-surface pl-9 pr-3 text-sm placeholder:text-ink-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
      />
      {open && debounced.length >= 2 && (
        <ul role="listbox" className="absolute z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-lg bg-surface py-1 shadow-xl ring-1 ring-ink-200">
          {!results.data?.data?.length && <li className="px-3 py-2 text-sm text-ink-500">{results.isLoading ? t("Searching…") : t("No customer found.")}</li>}
          {results.data?.data?.map((c) => (
            <li
              key={c.id}
              role="option"
              aria-selected={false}
              onMouseDown={(e) => {
                e.preventDefault();
                onChange(c);
                setTerm("");
                setOpen(false);
              }}
              className={cn("flex cursor-pointer items-center justify-between gap-2 px-3 py-2 hover:bg-brand-50")}
            >
              <span className="min-w-0">
                <span className="block truncate text-sm font-medium">{c.name}</span>
                <span className="block truncate text-xs text-ink-500">{c.phone ?? c.code}</span>
              </span>
              {toNumber(c.balance) > 0 && <span className="num shrink-0 text-xs text-ember-600">{t("owes {balance}", { balance: money(c.balance) })}</span>}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
