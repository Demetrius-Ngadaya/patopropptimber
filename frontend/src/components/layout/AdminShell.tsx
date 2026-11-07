"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Building2, ChevronDown, KeyRound, LogOut, Menu, Store, UserRound, X } from "lucide-react";
import { useAuth } from "@/lib/auth";
import { cn } from "@/lib/format";
import { SkeletonShell } from "@/components/ui";
import { navigation } from "./nav";
import { focusMenuSearch, MenuSearch, useMenuShortcut } from "./MenuSearch";
import { IdleTimeout } from "./IdleTimeout";
import { LanguageSwitch, ThemeSwitch } from "./PreferenceSwitches";
import { BrandLogo, useBusinessName } from "@/components/brand/BrandLogo";
import { useT } from "@/lib/i18n";
import { copyrightLine, tabTitle } from "@/lib/brand";
import { NotificationBell } from "@/features/notifications/NotificationBell";
import { InboxButton, PendingMessagesDialog, usePendingMessages } from "@/features/messages/PendingMessages";

function BranchSwitcher() {
  const t = useT();
  const { user, branchId, setBranch } = useAuth();
  if (!user?.branches?.length) return null;
  if (!user.all_branches) {
    return (
      <span className="hidden items-center gap-1.5 rounded-md bg-ink-100 px-2.5 py-1.5 text-[13px] font-medium text-ink-700 sm:inline-flex">
        <Building2 className="size-4" aria-hidden /> {user.branch?.name}
      </span>
    );
  }
  return (
    <label className="relative inline-flex items-center">
      <span className="sr-only">{t("Working branch")}</span>
      <Building2 className="pointer-events-none absolute left-2.5 size-4 text-ink-500" aria-hidden />
      <select
        value={branchId ?? ""}
        onChange={(e) => setBranch(e.target.value)}
        className="h-9 max-w-[200px] appearance-none rounded-md border border-ink-200 bg-surface pl-8 pr-7 text-[13px] font-medium text-ink-800 focus:border-brand-500 focus:outline-none"
      >
        <option value="all">{t("All branches")}</option>
        {user.branches.map((b) => (
          <option key={b.id} value={String(b.id)}>{b.name}</option>
        ))}
      </select>
      <ChevronDown className="pointer-events-none absolute right-2 size-4 text-ink-500" aria-hidden />
    </label>
  );
}

function UserMenu() {
  const t = useT();
  const { user, logout } = useAuth();
  const [open, setOpen] = useState(false);
  if (!user) return null;
  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        className="flex items-center gap-2 rounded-md px-2 py-1.5 text-left hover:bg-ink-100"
      >
        <span className="grid size-8 place-items-center rounded-full bg-night-800 text-[13px] font-semibold text-white">
          {user.name.split(" ").map((p) => p[0]).slice(0, 2).join("")}
        </span>
        <span className="hidden leading-tight md:block">
          <span className="block text-[13px] font-semibold text-ink-900">{user.name}</span>
          <span className="block text-xs text-ink-500">{user.role?.name}</span>
        </span>
        <ChevronDown className="size-4 text-ink-500" aria-hidden />
      </button>
      {open && (
        <>
          <div className="fixed inset-0 z-30" onClick={() => setOpen(false)} aria-hidden />
          <div className="absolute right-0 z-40 mt-1 w-56 overflow-hidden rounded-lg bg-surface py-1 shadow-xl ring-1 ring-ink-200">
            <Link href="/admin/profile" onClick={() => setOpen(false)} className="flex items-center gap-2 px-3 py-2 text-sm text-ink-700 hover:bg-ink-50">
              <UserRound className="size-4" /> {t("My profile")}
            </Link>
            <Link href="/admin/profile#password" onClick={() => setOpen(false)} className="flex items-center gap-2 px-3 py-2 text-sm text-ink-700 hover:bg-ink-50">
              <KeyRound className="size-4" /> {t("Change password")}
            </Link>
            <Link href="/" target="_blank" onClick={() => setOpen(false)} className="flex items-center gap-2 px-3 py-2 text-sm text-ink-700 hover:bg-ink-50">
              <Store className="size-4" /> {t("Open online shop")}
            </Link>
            <button type="button" onClick={() => logout(t("You have been logged out."))} className="flex w-full items-center gap-2 border-t border-ink-100 px-3 py-2 text-sm text-ember-600 hover:bg-ember-50">
              <LogOut className="size-4" /> {t("Log out")}
            </button>
          </div>
        </>
      )}
    </div>
  );
}

export function AdminShell({ children }: { children: React.ReactNode }) {
  const t = useT();
  const { user, loading, can } = useAuth();
  const businessName = useBusinessName();
  const pathname = usePathname();
  const router = useRouter();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [menuQuery, setMenuQuery] = useState("");
  const openMenuSearch = useCallback(() => {
    // On phones the menu is a drawer: open it first, then focus once it is drawn.
    if (window.matchMedia("(max-width: 1023px)").matches) setMobileOpen(true);
    setTimeout(focusMenuSearch, 30);
  }, []);
  useMenuShortcut(openMenuSearch);

  // Messages from management: unread count, and reminders / announcements to show now.
  const messagesOn = Boolean(user && user.type === "staff" && !user.must_change_password);
  const messages = usePendingMessages(messagesOn);
  const refetchMessages = messages.refetch;
  useEffect(() => {
    if (!messagesOn) return;
    const id = setInterval(() => document.visibilityState === "visible" && refetchMessages(), 120000);
    return () => clearInterval(id);
  }, [refetchMessages, messagesOn]);

  useEffect(() => {
    setMobileOpen(false);
  }, [pathname]);

  // Browser tab: "PatoPro — Dashboard", from the menu entry of the open page.
  const pageLabel = useMemo(() => {
    const matches = (href: string) => (href === "/admin" ? pathname === "/admin" : pathname === href || pathname.startsWith(href + "/"));
    return navigation.flatMap((g) => g.items).filter((i) => matches(i.href)).sort((a, b) => b.href.length - a.href.length)[0]?.label;
  }, [pathname]);
  useEffect(() => {
    const title = tabTitle(pageLabel ? t(pageLabel) : undefined);
    const apply = () => { if (document.title !== title) document.title = title; };
    apply();
    // Next writes its default <title> when the page's metadata streams in after a full load; keep ours.
    const observer = new MutationObserver(apply);
    observer.observe(document.head, { childList: true, subtree: true, characterData: true });
    return () => observer.disconnect();
  }, [pageLabel, t]);

  useEffect(() => {
    if (loading) return;
    if (!user) router.replace(`/login?next=${encodeURIComponent(pathname)}`);
    else if (user.type !== "staff") router.replace("/account");
    else if (user.must_change_password && pathname !== "/admin/profile") router.replace("/admin/profile?first=1");
    // Roles that must use two-factor login are sent to set it up first.
    else if (user.two_factor_required && !user.two_factor_enabled && pathname !== "/admin/profile") router.replace("/admin/profile?security=1");
  }, [loading, user, pathname, router]);

  const groups = useMemo(
    () => navigation.map((g) => ({ ...g, items: g.items.filter((i) => can(i.perm)) })).filter((g) => g.items.length),
    [can],
  );

  if (loading || !user || user.type !== "staff") {
    return (
      <SkeletonShell />
    );
  }

  const isActive = (href: string) => (href === "/admin" ? pathname === "/admin" : pathname === href || pathname.startsWith(href + "/"));
  // Exact match for nested items like /admin/stock vs /admin/stock/movements
  const activeHref = groups.flatMap((g) => g.items).map((i) => i.href).filter(isActive).sort((a, b) => b.length - a.length)[0];

  const sidebar = (
    <nav aria-label={t("Main")} className="flex h-full flex-col">
      <div className="flex h-16 items-center gap-2.5 px-4">
        <BrandLogo size="sm" />
        <span className="truncate text-[15px] font-semibold tracking-tight text-white">{businessName}</span>
      </div>
      <MenuSearch groups={groups} query={menuQuery} onQuery={setMenuQuery} onNavigate={() => setMobileOpen(false)} />
      <div className={cn("flex-1 space-y-5 overflow-y-auto px-3 pb-6 pt-2", menuQuery && "hidden")}>
        {groups.map((group) => (
          <div key={group.label}>
            <p className="px-2 pb-1.5 text-xs font-medium text-ink-400">{t(group.label)}</p>
            <ul className="space-y-0.5">
              {group.items.map((item) => {
                const active = item.href === activeHref;
                return (
                  <li key={item.href}>
                    <Link
                      href={item.href}
                      aria-current={active ? "page" : undefined}
                      className={cn(
                        "flex items-center gap-2.5 rounded-md px-2 py-1.5 text-[13.5px] transition-colors",
                        active ? "bg-white/10 font-semibold text-white" : "text-ink-300 hover:bg-white/5 hover:text-white",
                      )}
                    >
                      <item.icon className={cn("size-[18px]", active ? "text-saffron-300" : "text-ink-400")} aria-hidden />
                      {t(item.label)}
                    </Link>
                  </li>
                );
              })}
            </ul>
          </div>
        ))}
      </div>
    </nav>
  );

  return (
    <div className="min-h-dvh lg:pl-60 print:pl-0">
      <aside className="keep-light no-print fixed inset-y-0 left-0 z-40 hidden w-60 bg-night-900 lg:block">{sidebar}</aside>

      {mobileOpen && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <div className="absolute inset-0 bg-night-950/60" onClick={() => setMobileOpen(false)} aria-hidden />
          <aside className="keep-light absolute inset-y-0 left-0 w-64 bg-night-900 shadow-2xl">
            <button type="button" onClick={() => setMobileOpen(false)} className="absolute right-2 top-3 rounded-md p-1.5 text-ink-300 hover:bg-white/10" aria-label={t("Close menu")}>
              <X className="size-5" />
            </button>
            {sidebar}
          </aside>
        </div>
      )}

      <header className="no-print sticky top-0 z-30 flex h-14 items-center justify-between gap-3 border-b border-ink-200 bg-surface/95 px-4 backdrop-blur">
        <div className="flex items-center gap-2">
          <button type="button" className="rounded-md p-1.5 text-ink-700 hover:bg-ink-100 lg:hidden" onClick={() => setMobileOpen(true)} aria-label={t("Open menu")}>
            <Menu className="size-5" />
          </button>
          <BranchSwitcher />
        </div>
        <div className="flex items-center gap-1">
          <LanguageSwitch compact />
          <ThemeSwitch />
          <InboxButton unread={messages.data?.data.unread ?? 0} />
          <NotificationBell />
          <UserMenu />
        </div>
      </header>

      <main className="mx-auto w-full max-w-[1500px] px-4 py-5 sm:px-6">{children}</main>
      <footer className="no-print mx-auto w-full max-w-[1500px] px-4 pb-5 pt-2 text-center text-xs text-ink-400 sm:px-6">{copyrightLine()}</footer>
      <IdleTimeout />
      {Boolean(messages.data?.data.pending.length) && <PendingMessagesDialog key={messages.data!.data.pending.map((r) => r.id).join(",")} rows={messages.data!.data.pending} onDone={() => messages.refetch()} />}
    </div>
  );
}
