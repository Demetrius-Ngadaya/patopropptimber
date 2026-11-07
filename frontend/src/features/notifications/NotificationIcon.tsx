import { AlertTriangle, BarChart3, CalendarDays, CircleSlash, Coins, Info, PackageX, ShoppingBag, Wallet } from "lucide-react";
import { cn } from "@/lib/format";
import type { AppNotification } from "./api";

const ICONS: Record<string, typeof Info> = {
  low_stock: AlertTriangle,
  out_of_stock: PackageX,
  stock_digest: AlertTriangle,
  sales_report: BarChart3,
  debt_overdue: Wallet,
  new_order: ShoppingBag,
  sale_cancelled: CircleSlash,
  leave_request: CalendarDays,
  payroll_prepared: Coins,
};

const TONES: Record<AppNotification["level"], string> = {
  info: "bg-night-800/10 text-night-800 dark:bg-white/10 dark:text-ink-200",
  success: "bg-brand-100 text-brand-700",
  warning: "bg-saffron-100 text-saffron-700",
  danger: "bg-ember-100 text-ember-700",
};

export function NotificationIcon({ n, className }: { n: Pick<AppNotification, "type" | "level">; className?: string }) {
  const Icon = ICONS[n.type] ?? Info;
  return (
    <span className={cn("grid size-8 shrink-0 place-items-center rounded-full", TONES[n.level] ?? TONES.info, className)}>
      <Icon className="size-4" aria-hidden />
    </span>
  );
}
