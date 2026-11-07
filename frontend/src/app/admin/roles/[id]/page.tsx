"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { use, useEffect, useMemo, useState } from "react";
import { ArrowLeft, Eye, MousePointerClick, Trash2 } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, ConfirmDialog, ErrorState, Field, Input, PageHeader, Panel, Spinner, Switch } from "@/components/ui";
import { cn } from "@/lib/format";
import { useT } from "@/lib/i18n";

type Permission = { id: number; key: string; module: string; action: string; type: "page" | "action"; label: string };
type Module = { module: string; label: string; permissions: Permission[] };
type Role = { id: number; name: string; description: string | null; is_system: boolean; is_super_admin: boolean; all_branches: boolean; users_count: number; permission_keys: string[] };

/** Create (id = "new") or edit a role with a page / action permission matrix. */
export default function RoleEditor({ params }: { params: Promise<{ id: string }> }) {
  const t = useT();
  const { id } = use(params);
  const isNew = id === "new";
  const router = useRouter();
  const { can, refresh } = useAuth();
  const modules = useApi<ApiResponse<Module[]>>("admin/roles/permissions");
  const role = useApi<ApiResponse<Role>>(isNew ? null : `admin/roles/${id}`);
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [allBranches, setAllBranches] = useState(false);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [deleting, setDeleting] = useState(false);
  const r = role.data?.data;
  const editable = isNew ? can("roles.create") : can("roles.update");

  useEffect(() => {
    if (r) {
      setName(r.name);
      setDescription(r.description ?? "");
      setAllBranches(r.all_branches);
      setSelected(new Set(r.permission_keys));
    }
  }, [r]);

  const save = useAction(
    () => {
      const body = { name, description: description || null, all_branches: allBranches, permissions: [...selected] };
      return isNew ? api.post<ApiResponse<{ id: number }>>("admin/roles", body) : api.put<ApiResponse<{ id: number }>>(`admin/roles/${id}`, body);
    },
    { invalidate: [["admin/roles"], [`admin/roles/${id}`]], onSuccess: (res) => { refresh(); if (isNew) router.replace(`/admin/roles/${res.data.id}`); } },
  );
  const remove = useAction(() => api.del(`admin/roles/${id}`), { invalidate: [["admin/roles"]], onSuccess: () => router.replace("/admin/roles") });
  const errors = fieldErrors(save.error);

  const toggle = (key: string, on: boolean) => setSelected((s) => {
    const n = new Set(s);
    if (on) n.add(key); else n.delete(key);
    // Any action in a module needs at least one page permission to be useful.
    return n;
  });
  const toggleModule = (m: Module, on: boolean) => setSelected((s) => {
    const n = new Set(s);
    m.permissions.forEach((p) => (on ? n.add(p.key) : n.delete(p.key)));
    return n;
  });
  const total = useMemo(() => modules.data?.data.reduce((s, m) => s + m.permissions.length, 0) ?? 0, [modules.data]);

  if (modules.isLoading || (!isNew && role.isLoading)) return <Spinner variant="page" />;
  if (modules.error || role.error) return <ErrorState message={(modules.error ?? role.error)!.message} />;
  const superAdmin = r?.is_super_admin;

  return (
    <Guard perm="roles.view">
      <PageHeader
        back={<Link href="/admin/roles" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Roles")}</Link>}
        title={isNew ? t("New role") : r?.name ?? t("Role")}
        description={!isNew && r ? t("{users_count} people have this role. Changes apply to them immediately.", { users_count: r.users_count }) : t("Tick what people with this role can open and do.")}
        actions={
          <>
            {!isNew && !r?.is_system && can("roles.delete") && <Button variant="ghost" className="text-ember-600" icon={<Trash2 className="size-4" />} onClick={() => setDeleting(true)}>{t("Delete role")}</Button>}
            {editable && <Button loading={save.isPending} disabled={!name.trim()} onClick={() => save.mutate(undefined)}>{isNew ? t("Create role") : t("Save role")}</Button>}
          </>
        }
      />
      <div className="grid gap-5 lg:grid-cols-[300px_1fr]">
        <div className="space-y-5 lg:sticky lg:top-20 lg:self-start">
          <Panel>
            <div className="space-y-4">
              <Field label={t("Role name")} required error={errors.name}>{(p) => <Input {...p} disabled={!editable} value={name} onChange={(e) => setName(e.target.value)} />}</Field>
              <Field label={t("Description")}>{(p) => <Input {...p} disabled={!editable} value={description} onChange={(e) => setDescription(e.target.value)} />}</Field>
              <Switch label={t("Works in all branches")} description={t("Members can switch between branches.")} checked={allBranches || Boolean(superAdmin)} disabled={!editable || superAdmin} onChange={setAllBranches} />
              <p className="text-sm text-ink-600">{t("{count} of {total} permissions", { count: superAdmin ? total : selected.size, total })}</p>
              <div className="space-y-1.5 text-[12.5px] text-ink-500">
                <p className="flex items-center gap-1.5"><Eye className="size-3.5" /> {t("Page: can open the screen")}</p>
                <p className="flex items-center gap-1.5"><MousePointerClick className="size-3.5" /> {t("Action: can use the button / do the task")}</p>
              </div>
            </div>
          </Panel>
        </div>
        <div className="space-y-4">
          {superAdmin && <p className="rounded-md bg-night-900 px-4 py-3 text-sm text-white">{t("The Super Admin role always has every permission and cannot be limited.")}</p>}
          {modules.data?.data.map((m) => {
            const on = m.permissions.filter((p) => selected.has(p.key)).length;
            const all = on === m.permissions.length;
            return (
              <Panel key={m.module} title={<span className="flex items-center gap-2">{m.label} <span className="num text-[13px] font-normal text-ink-500">{superAdmin ? m.permissions.length : on}/{m.permissions.length}</span></span>}
                actions={editable && !superAdmin && <button type="button" className="text-[13px] font-medium text-brand-700 hover:underline" onClick={() => toggleModule(m, !all)}>{all ? t("Clear all") : t("Allow all")}</button>}>
                <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                  {m.permissions.map((p) => {
                    const checked = superAdmin || selected.has(p.key);
                    return (
                      <label key={p.key} className={cn("flex cursor-pointer items-start gap-2.5 rounded-md p-2 ring-1 transition-colors", checked ? "bg-brand-50/60 ring-brand-100" : "ring-ink-100 hover:bg-ink-50", (!editable || superAdmin) && "cursor-default")}>
                        <input type="checkbox" className="mt-0.5 size-4 accent-brand-600" checked={checked} disabled={!editable || superAdmin} onChange={(e) => toggle(p.key, e.target.checked)} />
                        <span className="min-w-0">
                          <span className="block text-sm text-ink-900">{p.label}</span>
                          <span className="mt-0.5 flex items-center gap-1 text-xs text-ink-500">{p.type === "page" ? <Eye className="size-3" /> : <MousePointerClick className="size-3" />}<code className="truncate">{p.key}</code></span>
                        </span>
                      </label>
                    );
                  })}
                </div>
              </Panel>
            );
          })}
          {r?.is_system && <Badge>{t("Built-in role: its name and permissions can be changed, but it cannot be deleted.")}</Badge>}
        </div>
      </div>
      <ConfirmDialog open={deleting} onClose={() => setDeleting(false)} onConfirm={() => remove.mutate(undefined)} loading={remove.isPending} title={t("Delete the {name} role?", { name: r?.name })} message={t("Only roles with no people can be deleted. It can be restored later.")} confirmLabel={t("Delete role")} />
    </Guard>
  );
}
