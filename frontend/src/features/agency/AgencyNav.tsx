"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { cn } from "@/lib/format";
import { useAuth } from "@/lib/auth";
import { useT } from "@/lib/i18n";
import { PROVIDER_COLORS } from "./types";

const TABS = [
  { href: "/admin/agency", label: "Today", perm: ["agency.view"] },
  { href: "/admin/agency/days", label: "Days", perm: ["agency.view"] },
  { href: "/admin/agency/commissions", label: "Commissions", perm: ["agency.view", "agency.commissions"] },
  { href: "/admin/agency/accounts", label: "Lines", perm: ["agency.manage"] },
];

/** Tabs across the uwakala pages. */
export function AgencyNav() {
  const t = useT();
  const path = usePathname();
  const { can } = useAuth();
  return (
    <nav className="mb-5 flex gap-1 overflow-x-auto border-b border-ink-200" aria-label={t("Uwakala")}>
      {TABS.filter((x) => can(x.perm)).map((x) => {
        const on = x.href === "/admin/agency" ? path === x.href : path.startsWith(x.href);
        return (
          <Link key={x.href} href={x.href} aria-current={on ? "page" : undefined}
            className={cn("-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium", on ? "border-brand-600 text-brand-700" : "border-transparent text-ink-500 hover:text-ink-800")}>
            {t(x.label)}
          </Link>
        );
      })}
    </nav>
  );
}

export function ProviderDot({ provider, className }: { provider: string; className?: string }) {
  return <span className={cn("inline-block size-2.5 shrink-0 rounded-full", PROVIDER_COLORS[provider] ?? PROVIDER_COLORS.other, className)} aria-hidden />;
}
