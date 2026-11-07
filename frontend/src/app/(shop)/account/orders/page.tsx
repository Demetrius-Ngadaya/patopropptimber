"use client";

import Link from "next/link";
import { useState } from "react";
import { ChevronRight } from "lucide-react";
import type { Paginated } from "@/lib/api";
import { date, money } from "@/lib/format";
import { useApi } from "@/lib/hooks";
import { usePublicSettings } from "@/lib/public-settings";
import { AccountNav } from "@/features/shop/components/AccountNav";
import { EmptyState, ErrorState, Pagination, Spinner, StatusBadge } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Order = { id: number; number: string; status: string; delivery_method: string; total: string | number; items_count: number; created_at: string; branch?: { name: string } };

export default function MyOrdersPage() {
  const t = useT();
  const [page, setPage] = useState(1);
  const { settings } = usePublicSettings();
  const q = useApi<Paginated<Order>>("shop/account/orders", { page });

  return (
    <>
      <h1 className="mb-4 text-2xl font-semibold text-ink-950">{t("My account")}</h1>
      <AccountNav />
      {q.isLoading ? <Spinner /> : q.error ? <ErrorState message={q.error.message} onRetry={() => q.refetch()} /> : !q.data?.data.length ? (
        <EmptyState title={t("No orders yet")} message={t("When you place an order it shows here, with its progress.")} action={<Link href="/" className="font-medium text-brand-700 hover:underline">{t("Start shopping")}</Link>} />
      ) : (
        <>
          <ul className="divide-y divide-ink-100 overflow-hidden rounded-lg bg-surface ring-1 ring-ink-200/70">
            {q.data.data.map((o) => (
              <li key={o.id}>
                <Link href={`/account/orders/${encodeURIComponent(o.number)}`} className="flex items-center gap-4 p-4 hover:bg-ink-50">
                  <div className="min-w-0 flex-1">
                    <p className="num font-medium text-ink-900">{o.number}</p>
                    <p className="text-sm text-ink-500">{t("{created_at} · {items_count} item", { created_at: date(o.created_at), items_count: o.items_count })}{o.items_count === 1 ? "" : "s"} · {o.delivery_method === "delivery" ? t("Delivery") : t("Collect at {value}", { value: o.branch?.name ?? "shop" })}</p>
                  </div>
                  <div className="text-right">
                    <p className="num font-semibold">{settings?.currency} {money(o.total)}</p>
                    <StatusBadge status={o.status} />
                  </div>
                  <ChevronRight className="size-4 shrink-0 text-ink-400" />
                </Link>
              </li>
            ))}
          </ul>
          <Pagination meta={q.data.meta} onPage={setPage} />
        </>
      )}
    </>
  );
}
