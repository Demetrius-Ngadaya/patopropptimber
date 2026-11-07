"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { Plus, RotateCcw } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { useAction, useApi } from "@/lib/hooks";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, Checkbox, DataTable, PageHeader, Panel } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Role = { id: number; name: string; slug: string; description: string | null; is_system: boolean; is_super_admin: boolean; all_branches: boolean; users_count: number; permissions_count: number; deleted_at: string | null };

export default function RolesPage() {
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const [trashed, setTrashed] = useState(false);
  const list = useApi<ApiResponse<Role[]>>("admin/roles", { trashed: trashed ? "only" : undefined });
  const restore = useAction((id: number) => api.post(`admin/roles/${id}/restore`), { invalidate: [["admin/roles"]] });

  return (
    <Guard perm="roles.view">
      <PageHeader title={t("Roles & permissions")} description={t("A role decides which pages a person can open and which buttons and actions they can use.")} actions={can("roles.create") && <Button icon={<Plus className="size-4" />} onClick={() => router.push("/admin/roles/new")}>{t("New role")}</Button>} />
      <Panel padded={false}>
        {can("roles.restore") && <div className="border-b border-ink-100 p-3"><Checkbox label={t("Show deleted roles")} checked={trashed} onChange={(e) => setTrashed(e.target.checked)} /></div>}
        <DataTable rows={list.data?.data} loading={list.isFetching} rowKey={(r) => r.id} onRowClick={trashed ? undefined : (r) => router.push(`/admin/roles/${r.id}`)} columns={[
          { key: "name", header: t("Role"), render: (r) => <span className="font-medium">{r.name}{r.description && <span className="block text-xs font-normal text-ink-500">{r.description}</span>}</span> },
          { key: "flags", header: "", render: (r) => <span className="flex gap-1">{r.is_super_admin && <Badge tone="navy">{t("Everything")}</Badge>}{r.is_system && <Badge>{t("Built-in")}</Badge>}{r.all_branches && <Badge tone="blue">{t("All branches")}</Badge>}</span> },
          { key: "permissions_count", header: t("Permissions"), align: "right", render: (r) => (r.is_super_admin ? "All" : r.permissions_count) },
          { key: "users_count", header: t("People"), align: "right" },
          ...(trashed ? [{ key: "_r", header: "", align: "right" as const, render: (r: Role) => <Button size="sm" variant="secondary" icon={<RotateCcw className="size-3.5" />} onClick={() => restore.mutate(r.id)}>{t("Restore")}</Button> }] : []),
        ]} empty={{ title: trashed ? t("No deleted roles") : t("No roles") }} />
      </Panel>
    </Guard>
  );
}
