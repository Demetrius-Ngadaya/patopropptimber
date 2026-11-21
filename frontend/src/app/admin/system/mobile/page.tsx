"use client";

import { useEffect, useState } from "react";
import { Lock, Server } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { DEVELOPER } from "@/lib/auth";
import { useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Button, Field, Input, PageHeader, Panel, Spinner } from "@/components/ui";

/**
 * Software Engineer only: the server address every installed PatoPro app uses.
 * Users never see it in the app. Each app tests the new address first and only
 * moves to it when it answers, so a typo cannot cut the apps off.
 */
export default function SystemMobilePage() {
  const t = useT();
  const data = useApi<ApiResponse<{ api_url: string; built_in: string }>>("admin/system/mobile");
  const [url, setUrl] = useState("");
  const [test, setTest] = useState<{ ok: boolean; text: string } | null>(null);
  const [testing, setTesting] = useState(false);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- start the field from the saved value
    if (data.data) setUrl(data.data.data.api_url);
  }, [data.data]);

  const save = useAction(() => api.put("admin/system/mobile", { api_url: url.trim() || null }), { invalidate: [["admin/system/mobile"]] });

  const check = async () => {
    const base = url.trim().replace(/\/+$/, "").replace(/\/api(\/v1)?$/, "");
    if (!base) return;
    setTesting(true);
    setTest(null);
    try {
      const res = await fetch(`${base}/api/v1/health`, { cache: "no-store" });
      setTest(res.ok ? { ok: true, text: t("The server answers. Apps can use this address.") } : { ok: false, text: t("The server answered with error {code}.", { code: String(res.status) }) });
    } catch {
      setTest({ ok: false, text: t("No answer from this address. Check it before saving.") });
    } finally {
      setTesting(false);
    }
  };

  return (
    <Guard perm={DEVELOPER}>
      <PageHeader title={t("Mobile app server")} description={t("Only you (Software Engineer) see this. The app never shows the server address to its users.")} />
      {!data.data ? <Spinner /> : (
        <Panel title={t("Server address the app uses")} actions={<span className="inline-flex items-center gap-1 text-xs text-ink-500"><Lock className="size-3.5" />{t("Software Engineer")}</span>}>
          <div className="max-w-xl space-y-4">
            <Field label={t("Address")} hint={t("Example: https://api.yourbusiness.co.tz — leave empty to use the address built into the app.")}>
              {(p) => <Input {...p} value={url} onChange={(e) => { setUrl(e.target.value); setTest(null); }} placeholder="https://api.example.co.tz" inputMode="url" />}
            </Field>
            {test && <p className={test.ok ? "text-sm text-brand-700" : "text-sm text-ember-700"}>{test.text}</p>}
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" icon={<Server className="size-4" />} loading={testing} onClick={check} disabled={!url.trim()}>{t("Test the address")}</Button>
              <Button loading={save.isPending} onClick={() => save.mutate(undefined)}>{t("Save")}</Button>
            </div>
            <ul className="list-disc space-y-1 pl-5 text-sm text-ink-600">
              <li>{t("Each app checks the new address and moves to it only if it answers.")}</li>
              <li>{t("On a phone, the address can also be changed by typing 111999 in the username box of the sign-in screen and pressing Sign in.")}</li>
              <li>{t("Built-in address of this installation: {url}", { url: data.data.data.built_in })}</li>
            </ul>
          </div>
        </Panel>
      )}
    </Guard>
  );
}
