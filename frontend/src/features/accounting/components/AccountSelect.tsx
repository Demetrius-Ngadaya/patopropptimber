"use client";

import type { ApiResponse } from "@/lib/api";
import { useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Select } from "@/components/ui";
import { ACCOUNT_TYPE_LABELS, ACCOUNT_TYPES, type Account, type AccountType } from "../types";

export function useAccounts(options: { postable?: boolean; money?: boolean } = {}) {
  const q = useApi<ApiResponse<Account[]>>("admin/accounting/accounts", { postable: options.postable ? 1 : undefined, money: options.money ? 1 : undefined });
  return q.data?.data ?? [];
}

/** Account dropdown grouped by type. */
export function AccountSelect({
  value, onChange, types, postable = true, money = false, manualOnly = false, placeholder, id, ...rest
}: {
  value: number | "" | null;
  onChange: (id: number | "") => void;
  types?: AccountType[];
  postable?: boolean;
  money?: boolean;
  manualOnly?: boolean;
  placeholder?: string;
  id?: string;
  "aria-label"?: string;
  "aria-invalid"?: boolean;
  className?: string;
}) {
  const t = useT();
  const accounts = useAccounts({ postable, money }).filter((a) => (!types || types.includes(a.type)) && (!manualOnly || a.allow_manual));
  return (
    <Select id={id} value={value ?? ""} onChange={(e) => onChange(e.target.value ? Number(e.target.value) : "")} {...rest}>
      <option value="">{placeholder ?? t("Choose an account…")}</option>
      {ACCOUNT_TYPES.filter((ty) => !types || types.includes(ty)).map((ty) => {
        const group = accounts.filter((a) => a.type === ty);
        if (!group.length) return null;
        return (
          <optgroup key={ty} label={t(ACCOUNT_TYPE_LABELS[ty])}>
            {group.map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}
          </optgroup>
        );
      })}
    </Select>
  );
}
