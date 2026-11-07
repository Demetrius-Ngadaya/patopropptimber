"use client";

import Link from "next/link";
import type { ApiResponse } from "@/lib/api";
import { useApi } from "@/lib/hooks";
import { ErrorState, PageHeader, Spinner } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Report = { key: string; title: string; description: string; group: string };

export default function ReportsPage() {
  const t = useT();
  const q = useApi<ApiResponse<{ groups: Record<string, Report[]>; can_export: boolean }>>("admin/reports");

  return (
    <>
      <PageHeader title={t("Reports")} description={t("Every report respects the branch you have selected at the top. Choose All branches to compare.")} />
      {q.isLoading && <Spinner />}
      {q.error && <ErrorState message={q.error.message} />}
      {q.data && (
        <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
          {Object.entries(q.data.data.groups).map(([group, reports]) => (
            <section key={group} className="rounded-[var(--radius-panel)] bg-surface ring-1 ring-ink-200/70">
              <h2 className="border-b border-ink-100 px-4 py-3 text-[15px] font-semibold">{group}</h2>
              <ul className="divide-y divide-ink-100">
                {reports.map((r) => (
                  <li key={r.key}>
                    <Link href={`/admin/reports/${r.key}`} className="block px-4 py-3 hover:bg-brand-50/50">
                      <span className="block text-sm font-medium text-ink-900">{r.title}</span>
                      <span className="block text-[13px] text-ink-500">{r.description}</span>
                    </Link>
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>
      )}
    </>
  );
}
