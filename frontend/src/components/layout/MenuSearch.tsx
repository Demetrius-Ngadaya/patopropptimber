"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { CornerDownLeft, Search, X } from "lucide-react";
import { cn } from "@/lib/format";
import { useT } from "@/lib/i18n";
import type { NavGroup, NavItem } from "./nav";

/** Lower-case, no accents, so "Nukuu" / "nukuu" and "Ankara" all match. */
const fold = (s: string) => s.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();

type Hit = NavItem & { group: string; score: number };

/** Items matching every word of the query, in English or the user's language. Labels beat keywords. */
export function searchMenu(groups: NavGroup[], query: string, t: (s: string) => string): Hit[] {
  const words = fold(query).split(/\s+/).filter(Boolean);
  if (!words.length) return [];
  const hits: Hit[] = [];
  for (const g of groups) {
    for (const item of g.items) {
      const label = fold(`${t(item.label)} ${item.label}`);
      const extra = fold(`${t(g.label)} ${g.label} ${item.keywords ?? ""}`);
      let score = 0;
      const all = words.every((w) => {
        if (label.split(/\s+/).some((p) => p.startsWith(w))) { score += 3; return true; }
        if (label.includes(w)) { score += 2; return true; }
        if (extra.includes(w)) { score += 1; return true; }
        return false;
      });
      if (all) hits.push({ ...item, group: g.label, score });
    }
  }
  return hits.sort((a, b) => b.score - a.score).slice(0, 12);
}

/** Focus the menu search box that is on screen (desktop sidebar or the open mobile menu). */
export function focusMenuSearch() {
  const boxes = Array.from(document.querySelectorAll<HTMLInputElement>("[data-menu-search]"));
  boxes.find((b) => b.offsetParent !== null)?.focus();
}

/**
 * Search box at the top of the sidebar. While there is a query the menu shows
 * the matches instead of the groups: arrows move, Enter opens, Esc clears.
 */
export function MenuSearch({ groups, query, onQuery, onNavigate }: { groups: NavGroup[]; query: string; onQuery: (q: string) => void; onNavigate?: () => void }) {
  const t = useT();
  const router = useRouter();
  const [active, setActive] = useState(0);
  const hits = useMemo(() => searchMenu(groups, query, t), [groups, query, t]);
  // A new query starts at the best match again (state set while rendering, the React way of resetting on a prop change).
  const [lastQuery, setLastQuery] = useState(query);
  if (lastQuery !== query) {
    setLastQuery(query);
    setActive(0);
  }

  const go = (href: string) => {
    onQuery("");
    onNavigate?.();
    router.push(href);
  };

  return (
    <div className="px-3 pb-2">
      <div className="relative">
        <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-ink-400" aria-hidden />
        <input
          data-menu-search
          type="text"
          value={query}
          onChange={(e) => onQuery(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "ArrowDown") { e.preventDefault(); setActive((a) => Math.min(a + 1, hits.length - 1)); }
            if (e.key === "ArrowUp") { e.preventDefault(); setActive((a) => Math.max(a - 1, 0)); }
            if (e.key === "Enter" && hits[active]) { e.preventDefault(); go(hits[active].href); }
            if (e.key === "Escape") { onQuery(""); (e.target as HTMLInputElement).blur(); }
          }}
          placeholder={t("Search the menu")}
          aria-label={t("Search the menu")}
          aria-controls="menu-search-results"
          aria-activedescendant={hits[active] ? `menu-hit-${active}` : undefined}
          className="h-9 w-full rounded-md border border-white/10 bg-white/5 pl-8 pr-14 text-[13px] text-white placeholder:text-ink-400 focus:border-saffron-300/60 focus:bg-white/10 focus:outline-none"
        />
        {query ? (
          <button type="button" onClick={() => onQuery("")} className="absolute right-1.5 top-1/2 -translate-y-1/2 rounded p-1 text-ink-400 hover:text-white" aria-label={t("Clear")}>
            <X className="size-3.5" />
          </button>
        ) : (
          <kbd className="pointer-events-none absolute right-2 top-1/2 hidden -translate-y-1/2 rounded border border-white/15 px-1.5 text-[10.5px] text-ink-400 lg:block">Ctrl K</kbd>
        )}
      </div>
      {query && (
        <ul id="menu-search-results" role="listbox" aria-label={t("Menu results")} className="mt-2 space-y-0.5">
          {hits.length === 0 && <li className="px-2 py-3 text-[13px] text-ink-400">{t("Nothing in the menu matches “{query}”.", { query })}</li>}
          {hits.map((h, i) => (
            <li key={h.href} id={`menu-hit-${i}`} role="option" aria-selected={i === active}>
              <Link
                href={h.href}
                onClick={(e) => { e.preventDefault(); go(h.href); }}
                onMouseEnter={() => setActive(i)}
                className={cn("flex items-center gap-2.5 rounded-md px-2 py-1.5 text-[13.5px]", i === active ? "bg-white/10 text-white" : "text-ink-300")}
              >
                <h.icon className={cn("size-[18px] shrink-0", i === active ? "text-saffron-300" : "text-ink-400")} aria-hidden />
                <span className="min-w-0 flex-1">
                  <span className="block truncate">{t(h.label)}</span>
                  <span className="block truncate text-[11.5px] text-ink-400">{t(h.group)}</span>
                </span>
                {i === active && <CornerDownLeft className="size-3.5 text-ink-400" aria-hidden />}
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/** Ctrl/Cmd+K anywhere, or "/" when not typing, jumps to the menu search. */
export function useMenuShortcut(open: () => void) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const typing = e.target instanceof HTMLElement && (e.target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName));
      if (((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") || (e.key === "/" && !typing)) {
        e.preventDefault();
        open();
      }
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open]);
}
