"use client";

import { useEffect, useState } from "react";
import QRCode from "qrcode";
import { Copy, KeyRound, ShieldCheck, ShieldOff } from "lucide-react";
import { toast } from "sonner";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { fieldErrors, useAction } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Badge, Button, Field, Input, Modal, Panel } from "@/components/ui";

type Setup = { secret: string; otpauth_url: string };

function RecoveryCodes({ codes, onClose }: { codes: string[] | null; onClose: () => void }) {
  const t = useT();
  if (!codes) return null;
  const text = codes.join("\n");
  return (
    <Modal open onClose={onClose} title={t("Your recovery codes")} description={t("Each code logs you in once if you lose your phone. Store them somewhere safe and private. They will not be shown again.")}
      footer={<><Button variant="secondary" icon={<Copy className="size-4" />} onClick={() => navigator.clipboard.writeText(text).then(() => toast.success(t("Copied.")))}>{t("Copy")}</Button><Button onClick={onClose}>{t("I have saved them")}</Button></>}>
      <ul className="grid grid-cols-2 gap-2 rounded-lg bg-ink-50 p-4 font-mono text-sm">{codes.map((c) => <li key={c}>{c}</li>)}</ul>
    </Modal>
  );
}

/** Turn authenticator-app login on or off, and replace recovery codes. The QR code is drawn in the browser: the secret never leaves the page. */
export function TwoFactorPanel({ highlight }: { highlight?: boolean }) {
  const t = useT();
  const { user, refresh } = useAuth();
  const [setup, setSetup] = useState<Setup | null>(null);
  const [qr, setQr] = useState("");
  const [code, setCode] = useState("");
  const [codes, setCodes] = useState<string[] | null>(null);
  const [disabling, setDisabling] = useState(false);
  const [regen, setRegen] = useState(false);
  const [off, setOff] = useState({ password: "", code: "" });

  useEffect(() => {
    if (!setup) return;
    QRCode.toDataURL(setup.otpauth_url, { margin: 1, width: 200, errorCorrectionLevel: "M" }).then(setQr).catch(() => setQr(""));
  }, [setup]);

  const begin = useAction(() => api.post<ApiResponse<Setup>>("auth/two-factor/setup", {}), { silent: true, onSuccess: (r) => { setSetup(r.data); setCode(""); } });
  const confirm = useAction(() => api.post<ApiResponse<{ recovery_codes: string[] }>>("auth/two-factor/confirm", { code }), { onSuccess: (r) => { setSetup(null); setCodes(r.data.recovery_codes); refresh(); } });
  const disable = useAction(() => api.post("auth/two-factor/disable", off), { onSuccess: () => { setDisabling(false); setOff({ password: "", code: "" }); refresh(); } });
  const newCodes = useAction(() => api.post<ApiResponse<{ recovery_codes: string[] }>>("auth/two-factor/recovery-codes", { code }), { onSuccess: (r) => { setRegen(false); setCode(""); setCodes(r.data.recovery_codes); } });
  const ce = fieldErrors(confirm.error);
  if (!user) return null;
  const on = Boolean(user.two_factor_enabled);

  return (
    <Panel id="security" title={<span className="flex items-center gap-2">{t("Two-step login")}{on ? <Badge tone="green">{t("On")}</Badge> : <Badge tone={user.two_factor_required ? "red" : "neutral"}>{t("Off")}</Badge>}</span>}
      className={highlight && !on ? "ring-2 ring-saffron-500" : undefined}>
      {!on && user.two_factor_required && <p className="mb-3 rounded-lg bg-saffron-50 px-3 py-2 text-sm text-saffron-700">{t("Your role requires two-step login. Set it up to continue using the system.")}</p>}
      <p className="text-sm text-ink-600">{t("After your password, you also enter a 6-digit code from an authenticator app (Google Authenticator, Microsoft Authenticator, Authy). Someone who steals your password still cannot log in.")}</p>

      {!on && !setup && <Button className="mt-4" icon={<ShieldCheck className="size-4" />} loading={begin.isPending} onClick={() => begin.mutate(undefined)}>{t("Set up two-step login")}</Button>}

      {setup && (
        <div className="mt-4 grid gap-4 sm:grid-cols-[auto_1fr]">
          <div className="keep-light rounded-lg bg-white p-2 ring-1 ring-ink-200">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            {qr ? <img src={qr} alt={t("QR code for your authenticator app")} className="size-44" /> : <div className="size-44" />}
          </div>
          <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); confirm.mutate(undefined); }}>
            <ol className="list-decimal space-y-1 pl-4 text-sm text-ink-600">
              <li>{t("Open the authenticator app and scan the QR code.")}</li>
              <li>{t("Can't scan? Enter this key instead:")} <code className="break-all rounded bg-ink-100 px-1 font-mono text-xs">{setup.secret.replace(/(.{4})/g, "$1 ").trim()}</code></li>
              <li>{t("Type the 6-digit code the app shows.")}</li>
            </ol>
            <Field label={t("Code")} error={ce.code}>{(p) => <Input {...p} inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ""))} className="w-40 text-center font-mono text-lg tracking-[0.3em]" />}</Field>
            <div className="flex gap-2">
              <Button type="submit" loading={confirm.isPending} disabled={code.length !== 6}>{t("Turn on")}</Button>
              <Button type="button" variant="ghost" onClick={() => setSetup(null)}>{t("Cancel")}</Button>
            </div>
          </form>
        </div>
      )}

      {on && (
        <div className="mt-4 flex flex-wrap gap-2">
          <Button variant="secondary" icon={<KeyRound className="size-4" />} onClick={() => { setCode(""); setRegen(true); }}>{t("New recovery codes")}</Button>
          {!user.two_factor_required && <Button variant="ghost" icon={<ShieldOff className="size-4" />} onClick={() => setDisabling(true)}>{t("Turn off")}</Button>}
        </div>
      )}

      <RecoveryCodes codes={codes} onClose={() => setCodes(null)} />
      <Modal open={regen} onClose={() => setRegen(false)} title={t("New recovery codes")} description={t("Enter a code from your app. The old recovery codes stop working.")}
        footer={<><Button variant="secondary" onClick={() => setRegen(false)}>{t("Cancel")}</Button><Button loading={newCodes.isPending} disabled={code.length !== 6} onClick={() => newCodes.mutate(undefined)}>{t("Create")}</Button></>}>
        <Field label={t("Code")}>{(p) => <Input {...p} inputMode="numeric" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ""))} className="w-40 text-center font-mono text-lg tracking-[0.3em]" />}</Field>
      </Modal>
      <Modal open={disabling} onClose={() => setDisabling(false)} title={t("Turn off two-step login")} description={t("Your account will be protected by the password only.")}
        footer={<><Button variant="secondary" onClick={() => setDisabling(false)}>{t("Cancel")}</Button><Button variant="danger" loading={disable.isPending} disabled={!off.password || !off.code} onClick={() => disable.mutate(undefined)}>{t("Turn off")}</Button></>}>
        <div className="space-y-4">
          <Field label={t("Password")} required>{(p) => <Input {...p} type="password" autoComplete="current-password" value={off.password} onChange={(e) => setOff({ ...off, password: e.target.value })} />}</Field>
          <Field label={t("Code from your app or a recovery code")} required>{(p) => <Input {...p} value={off.code} onChange={(e) => setOff({ ...off, code: e.target.value })} />}</Field>
        </div>
      </Modal>
    </Panel>
  );
}
