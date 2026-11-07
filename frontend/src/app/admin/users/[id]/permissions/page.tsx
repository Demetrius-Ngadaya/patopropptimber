"use client";

import Link from "next/link";
import { use, useEffect, useMemo, useState } from "react";
import { ArrowLeft, Check, Eye, MousePointerClick, X } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { useAction, useApi } from "@/lib/hooks";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, ErrorState, PageHeader, Panel, SearchInput, Spinner } from "@/components/ui";
import { cn } from "@/lib/format";
import { useT } from "@/lib/i18n";

type Permission = { id: number; key: string; module: string; type: "page" | "action"; label: string };
type Module = { module: string; label: string; permissions: Permission[] };
type Data = {
  user: { id: number; name: string; username: string; role: { id: number; name: string } | null };
  role_permissions: string[];
  grants: string[];
  denies: string[];
  disabled_modules: string[];
  modules: Module[];
};
type Choice = "role" | "grant" | "deny";

/**
 * Permissions for ONE user on top of their role. "Role" follows the role (and
 * any later change to it); "Allow" / "Deny" apply to this person only.
 */
export default function UserPermissionsPage({ params }: { params: Promise<{ id: string }> }) {
  const t = useT();
  const { id } = use(params);
  const { user: me, can } = useAuth();
  const data = useApi<ApiResponse<Data>>(`admin/users/${id}/permissions`);
  const [choice, setChoice] = useState<Record<string, Choice>>({});
  const [search, setSearch] = useState("");
  const d = data.data?.data;

  useEffect(() => {
    if (!d) return;
    const next: Record<string, Choice> = {};
    d.grants.forEach((k) => (next[k] = "grant"));
    d.denies.forEach((k) => (next[k] = "deny"));
    setChoice(next);
  }, [d]);

  const roleKeys = useMemo(() => new Set(d?.role_permissions ?? []), [d]);
  // Only what the signed-in user holds can be handed out (super admins hold everything).
  const mayChange = (key: string) => Boolean(me?.role?.is_super_admin) || can(key);

  const save = useAction(
    () => api.put(`admin/users/${id}/permissions`, {
      grants: Object.keys(choice).filter((k) => choice[k] === "grant"),
      denies: Object.keys(choice).filter((k) => choice[k] === "deny"),
    }),
    { invalidate: [[`admin/users/${id}/permissions`]] },
  );

  const set = (key: string, value: Choice) => setChoice((c) => {
    const n = { ...c };
    // "Allow" on something the role already has, or "Deny" on something it lacks, is the same as the role.
    if (value === "role" || (value === "grant" && roleKeys.has(key)) || (value === "deny" && !roleKeys.has(key))) delete n[key];
    else n[key] = value;
    return n;
  });

  if (data.isLoading) return <Spinner variant="page" />;
  if (data.error) return <ErrorState message={data.error.message} />;

  const term = search.trim().toLowerCase();
  const changed = Object.keys(choice).length;
  const effective = (key: string) => (choice[key] === "grant" ? true : choice[key] === "deny" ? false : roleKeys.has(key));

  return (
    <Guard perm="users.permissions">
      <PageHeader
        back={<Link href="/admin/users" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Staff users")}</Link>}
        title={t("Permissions for {name}", { name: d?.user.name })}
        description={t("Start from the {role} role, then allow or deny single permissions for this person only. Other people with the same role are not affected.", { role: d?.user.role?.name ?? "—" })}
        actions={<Button loading={save.isPending} onClick={() => save.mutate(undefined)}>{t("Save permissions")}</Button>}
      />
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <SearchInput value={search} onChange={setSearch} placeholder={t("Find a permission")} className="w-full max-w-sm" />
        <Badge tone={changed ? "blue" : "neutral"}>{t("{count} personal change(s)", { count: changed })}</Badge>
        {changed > 0 && <button type="button" className="text-[13px] font-medium text-brand-700 hover:underline" onClick={() => setChoice({})}>{t("Reset to role")}</button>}
      </div>
      <div className="space-y-4">
        {d?.modules.map((m) => {
          const perms = m.permissions.filter((p) => !term || p.label.toLowerCase().includes(term) || p.key.includes(term));
          if (!perms.length) return null;
          const off = d?.disabled_modules.includes(m.module);
          return (
            <Panel key={m.module} title={<span className="flex items-center gap-2">{m.label}{off && <Badge tone="red">{t("Switched off")}</Badge>}</span>}>
              <ul className="divide-y divide-ink-100">
                {perms.map((p) => {
                  const c = choice[p.key] ?? "role";
                  const on = effective(p.key);
                  const locked = !mayChange(p.key);
                  return (
                    <li key={p.key} className={cn("flex flex-wrap items-center gap-3 py-2.5", off && "opacity-60")}>
                      <span className={cn("grid size-6 shrink-0 place-items-center rounded-full", on ? "bg-brand-100 text-brand-700" : "bg-ink-100 text-ink-500")} aria-label={on ? t("Allowed") : t("Not allowed")}>
                        {on ? <Check className="size-3.5" /> : <X className="size-3.5" />}
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="block text-sm text-ink-900">{p.label}</span>
                        <span className="mt-0.5 flex items-center gap-1 text-xs text-ink-500">
                          {p.type === "page" ? <Eye className="size-3" /> : <MousePointerClick className="size-3" />}
                          <code className="truncate">{p.key}</code>
                          <span>· {roleKeys.has(p.key) ? t("role allows") : t("role does not allow")}</span>
                        </span>
                      </span>
                      <div role="radiogroup" aria-label={p.label} className="inline-flex overflow-hidden rounded-md ring-1 ring-ink-200">
                        {(["role", "grant", "deny"] as Choice[]).map((v) => (
                          <button
                            key={v}
                            type="button"
                            role="radio"
                            aria-checked={c === v}
                            disabled={locked}
                            onClick={() => set(p.key, v)}
                            className={cn(
                              "px-2.5 py-1 text-xs font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50",
                              c === v ? (v === "grant" ? "bg-brand-600 text-white" : v === "deny" ? "bg-ember-600 text-white" : "bg-ink-800 text-white") : "bg-white text-ink-700 hover:bg-ink-50",
                            )}
                          >
                            {v === "role" ? t("Role") : v === "grant" ? t("Allow") : t("Deny")}
                          </button>
                        ))}
                      </div>
                    </li>
                  );
                })}
              </ul>
            </Panel>
          );
        })}
      </div>
    </Guard>
  );
}
