"use client";

import { useEffect, useState } from "react";
import { Lock } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { DEVELOPER, useAuth } from "@/lib/auth";
import { useAction, useApi } from "@/lib/hooks";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, ErrorState, PageHeader, Panel, Spinner, Switch } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Module = { key: string; label: string; always_on: boolean; enabled: boolean; permissions: number };

/**
 * Software Engineer only: choose which modules this installation has. A
 * switched-off module disappears for everyone, super admins included; its data
 * is kept and comes back when it is switched on again.
 */
export default function SystemModulesPage() {
  const t = useT();
  const { refresh } = useAuth();
  const list = useApi<ApiResponse<{ modules: Module[]; disabled: string[] }>>("admin/system/modules");
  const [off, setOff] = useState<Set<string>>(new Set());
  const modules = list.data?.data.modules;

  useEffect(() => {
    if (list.data) setOff(new Set(list.data.data.disabled));
  }, [list.data]);

  const save = useAction(() => api.put("admin/system/modules", { disabled: [...off] }), {
    invalidate: [["admin/system/modules"]],
    onSuccess: () => refresh(),
  });

  const toggle = (key: string, on: boolean) => setOff((s) => {
    const n = new Set(s);
    if (on) n.delete(key); else n.add(key);
    return n;
  });

  return (
    <Guard perm={DEVELOPER}>
      <PageHeader
        title={t("System modules")}
        description={t("Choose which parts of the system this business uses. A switched-off module disappears for every user, super admins included. Its data is kept and comes back when you switch it on.")}
        actions={<Button loading={save.isPending} disabled={!modules} onClick={() => save.mutate(undefined)}>{t("Save modules")}</Button>}
      />
      {list.isLoading ? <Spinner /> : list.error ? <ErrorState message={list.error.message} /> : (
        <Panel>
          <p className="mb-4 text-sm text-ink-600">{t("{count} of {total} modules switched on", { count: (modules?.length ?? 0) - off.size, total: modules?.length ?? 0 })}</p>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {modules?.map((m) => (
              <div key={m.key} className="rounded-md p-3 ring-1 ring-ink-100">
                {m.always_on ? (
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <p className="text-sm font-medium text-ink-900">{t(m.label)}</p>
                      <p className="mt-0.5 text-xs text-ink-500">{t("Needed to run the system")}</p>
                    </div>
                    <Badge><Lock className="mr-1 inline size-3" />{t("Always on")}</Badge>
                  </div>
                ) : (
                  <Switch
                    label={t(m.label)}
                    description={t("{count} permissions", { count: m.permissions })}
                    checked={!off.has(m.key)}
                    onChange={(on) => toggle(m.key, on)}
                  />
                )}
              </div>
            ))}
          </div>
        </Panel>
      )}
    </Guard>
  );
}
