"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { cn } from "@/lib/format";
import { useT } from "@/lib/i18n";

export function AccountNav() {
  const t = useT();
  const pathname = usePathname();
  const links = [
    { href: "/account/orders", label: t("My orders") },
    { href: "/account", label: t("My details") },
  ];
  return (
    <nav className="mb-6 flex gap-1 border-b border-ink-200" aria-label={t("Account")}>
      {links.map((l) => {
        const active = l.href === "/account" ? pathname === "/account" : pathname.startsWith(l.href);
        return (
          <Link key={l.href} href={l.href} className={cn("-mb-px border-b-2 px-3 py-2.5 text-sm font-medium", active ? "border-brand-600 text-ink-950" : "border-transparent text-ink-500 hover:text-ink-800")}>
            {l.label}
          </Link>
        );
      })}
    </nav>
  );
}
