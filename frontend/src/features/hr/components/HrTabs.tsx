"use client";

import Link from "next/link";
import { cn } from "@/lib/format";

/** Link-style sub navigation for HR pages that group several record types. */
export function SubNav({ items, active }: { items: Array<{ href: string; label: string; show?: boolean }>; active: string }) {
  return (
    <nav className="mb-4 flex gap-1 overflow-x-auto border-b border-ink-200">
      {items.filter((i) => i.show !== false).map((i) => (
        <Link key={i.href} href={i.href} className={cn("-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium", active === i.href ? "border-brand-600 text-brand-700" : "border-transparent text-ink-500 hover:text-ink-800")}>
          {i.label}
        </Link>
      ))}
    </nav>
  );
}
