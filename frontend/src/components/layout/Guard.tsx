"use client";

import { ShieldAlert } from "lucide-react";
import { useAuth } from "@/lib/auth";
import { EmptyState } from "@/components/ui";
import { useT } from "@/lib/i18n";

/** Shows a friendly message instead of the page when the user lacks the permission. */
export function Guard({ perm, children }: { perm: string | string[]; children: React.ReactNode }) {
  const t = useT();
  const { can } = useAuth();
  if (!can(perm)) {
    return (
      <EmptyState
        icon={<ShieldAlert className="size-10" />}
        title={t("You don't have access to this page")}
        message={t("Ask an administrator to give your role access if you need it.")}
      />
    );
  }
  return <>{children}</>;
}

/** Renders children only when the user has the permission (buttons, actions). */
export function Can({ perm, children }: { perm: string | string[]; children: React.ReactNode }) {
  const { can } = useAuth();
  return can(perm) ? <>{children}</> : null;
}
