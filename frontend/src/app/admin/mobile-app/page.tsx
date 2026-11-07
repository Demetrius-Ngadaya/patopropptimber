"use client";

import { useRef, useState } from "react";
import { Copy, Download, ExternalLink, Link2, Smartphone, Trash2, Upload } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError, type ApiResponse } from "@/lib/api";
import { date } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, Checkbox, ConfirmDialog, EmptyState, Field, Input, Modal, PageHeader, Panel, SkeletonTable, Textarea } from "@/components/ui";
import { QrCode } from "@/features/catalog/components/QrCode";
import { fileSize, type AppRelease } from "@/features/mobile-app/AppDownload";

type AdminRelease = AppRelease & {
  is_published: boolean;
  is_current: boolean;
  downloads: number;
  source: "upload" | "link";
  external_url: string | null;
  created_at: string | null;
};
type Overview = { current: AdminRelease | null; releases: AdminRelease[]; max_mb: number; public_page: string };

const KEY = ["admin/mobile/app-releases"];
const INVALIDATE = [KEY, ["public/app"]];

/** Sends the APK in pieces (the web server and hosting limit the size of one request). */
async function uploadApk(file: File, onProgress: (fraction: number) => void): Promise<string> {
  const start = await api.post<ApiResponse<{ upload_id: string; chunk_size: number }>>("admin/mobile/app-releases/uploads", {
    file_name: file.name,
    file_size: file.size,
  });
  const { upload_id, chunk_size } = start.data;
  const total = Math.ceil(file.size / chunk_size);
  for (let i = 0; i < total; i++) {
    const form = new FormData();
    form.append("index", String(i));
    form.append("chunk", file.slice(i * chunk_size, Math.min(file.size, (i + 1) * chunk_size)), "chunk");
    // One retry per piece: a dropped connection on a phone hotspot should not lose a 60 MB upload.
    try {
      await api.post(`admin/mobile/app-releases/uploads/${upload_id}/chunks`, form);
    } catch (e) {
      if (e instanceof ApiError && e.status && e.status < 500) throw e;
      await new Promise((r) => setTimeout(r, 1500));
      await api.post(`admin/mobile/app-releases/uploads/${upload_id}/chunks`, form);
    }
    onProgress((i + 1) / total);
  }
  return upload_id;
}

function NewReleaseForm({ open, onClose, nextBuild, maxMb }: { open: boolean; onClose: () => void; nextBuild: number; maxMb: number }) {
  const t = useT();
  const [mode, setMode] = useState<"upload" | "link">("upload");
  const [file, setFile] = useState<File | null>(null);
  const [link, setLink] = useState("");
  const [version, setVersion] = useState("");
  const [build, setBuild] = useState(String(nextBuild));
  const [notes, setNotes] = useState("");
  const [required, setRequired] = useState(false);
  const [publish, setPublish] = useState(true);
  const [progress, setProgress] = useState<number | null>(null);
  const [wasOpen, setWasOpen] = useState(open);

  // Reset the form each time it opens.
  if (open !== wasOpen) {
    setWasOpen(open);
    if (open) {
      setMode("upload"); setFile(null); setLink(""); setVersion(""); setBuild(String(nextBuild));
      setNotes(""); setRequired(false); setPublish(true); setProgress(null);
    }
  }

  const save = useAction(
    async () => {
      let upload_id: string | undefined;
      if (mode === "upload" && file) {
        setProgress(0);
        upload_id = await uploadApk(file, setProgress);
      }
      return api.post("admin/mobile/app-releases", {
        version: version.trim(),
        build_number: Number(build),
        upload_id,
        external_url: mode === "link" ? link.trim() : undefined,
        notes: notes.trim() || null,
        is_required: required,
        publish,
      });
    },
    { invalidate: INVALIDATE, onSuccess: onClose, onError: () => setProgress(null) },
  );
  const errors = fieldErrors(save.error);
  const tooBig = file ? file.size > maxMb * 1048576 : false;
  const ready = version.trim() && Number(build) > 0 && (mode === "upload" ? file && !tooBig : /^https:\/\//.test(link.trim()));

  return (
    <Modal
      open={open}
      onClose={save.isPending ? () => {} : onClose}
      title={t("Release a new app version")}
      description={t("Build the Android app (APK) with EAS, then upload it here. Everyone with the old app is told to update.")}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={save.isPending}>{t("Cancel")}</Button>
          <Button loading={save.isPending} disabled={!ready} icon={<Upload className="size-4" />} onClick={() => save.mutate(undefined)}>
            {publish ? t("Upload and publish") : t("Upload")}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="flex gap-2 text-sm">
          <Button size="sm" variant={mode === "upload" ? "primary" : "secondary"} onClick={() => setMode("upload")} icon={<Upload className="size-4" />}>{t("Upload APK file")}</Button>
          <Button size="sm" variant={mode === "link" ? "primary" : "secondary"} onClick={() => setMode("link")} icon={<Link2 className="size-4" />}>{t("Use a download link")}</Button>
        </div>

        {mode === "upload" ? (
          <div>
            <label className="flex cursor-pointer flex-col items-center gap-2 rounded-lg border-2 border-dashed border-ink-200 px-4 py-6 text-center text-sm text-ink-600 hover:border-brand-300 hover:bg-brand-50/40">
              <Smartphone className="size-8 text-ink-400" />
              {file ? (
                <span><span className="font-medium text-ink-900">{file.name}</span> · {fileSize(file.size)}</span>
              ) : (
                <span>{t("Choose the .apk file (up to {mb} MB)", { mb: maxMb })}</span>
              )}
              <input type="file" accept=".apk,application/vnd.android.package-archive" className="sr-only" onChange={(e) => setFile(e.target.files?.[0] ?? null)} disabled={save.isPending} />
            </label>
            {tooBig && <p className="mt-1 text-sm text-ember-700">{t("The app file must be smaller than {mb} MB.", { mb: maxMb })}</p>}
            {progress !== null && (
              <div className="mt-3">
                <div className="h-2 overflow-hidden rounded-full bg-ink-100">
                  <div className="h-full bg-brand-600 transition-[width]" style={{ width: `${Math.round(progress * 100)}%` }} />
                </div>
                <p className="mt-1 text-xs text-ink-500">{progress < 1 ? t("Uploading… {p}%", { p: Math.round(progress * 100) }) : t("Checking the file…")}</p>
              </div>
            )}
          </div>
        ) : (
          <Field label={t("Download link (https)")} error={errors.external_url} hint={t("For example the APK link EAS gives after a build. Downloads are sent to that address.")}>
            {(p) => <Input {...p} value={link} onChange={(e) => setLink(e.target.value)} placeholder="https://expo.dev/artifacts/eas/....apk" />}
          </Field>
        )}

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t("Version")} required error={errors.version} hint={t("As in app.json, e.g. 1.1.0")}>
            {(p) => <Input {...p} value={version} onChange={(e) => setVersion(e.target.value.replace(/[^0-9.]/g, ""))} placeholder="1.1.0" inputMode="decimal" maxLength={20} />}
          </Field>
          <Field label={t("Build number")} required error={errors.build_number} hint={t("Android versionCode; higher than the last one")}>
            {(p) => <Input {...p} value={build} onChange={(e) => setBuild(e.target.value.replace(/\D/g, ""))} inputMode="numeric" maxLength={10} />}
          </Field>
        </div>
        <Field label={t("What's new (optional)")} error={errors.notes} hint={t("Shown on the download page and in the update message in the app.")}>
          {(p) => <Textarea {...p} rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={2000} />}
        </Field>
        <Checkbox label={t("Offer for download now")} checked={publish} onChange={(e) => setPublish(e.target.checked)} />
        <Checkbox label={t("Required update: older apps must install this version before continuing")} checked={required} onChange={(e) => setRequired(e.target.checked)} />
      </div>
    </Modal>
  );
}

export default function MobileAppPage() {
  const t = useT();
  const q = useApi<ApiResponse<Overview>>("admin/mobile/app-releases");
  const data = q.data?.data;
  const releases = data?.releases ?? [];
  const current = data?.current ?? null;
  const [formOpen, setFormOpen] = useState(false);
  const [deleting, setDeleting] = useState<AdminRelease | null>(null);
  const linkRef = useRef<HTMLInputElement>(null);

  const update = useAction(({ id, ...body }: { id: number; is_published?: boolean; is_required?: boolean }) => api.put(`admin/mobile/app-releases/${id}`, body), { invalidate: INVALIDATE });
  const remove = useAction((id: number) => api.del(`admin/mobile/app-releases/${id}`), { invalidate: INVALIDATE, onSuccess: () => setDeleting(null) });
  const nextBuild = Math.max(0, ...releases.map((r) => r.build_number)) + 1;

  const copyLink = async () => {
    if (!data) return;
    try {
      await navigator.clipboard.writeText(data.public_page);
      toast.success(t("Link copied"));
    } catch {
      linkRef.current?.select();
    }
  };

  return (
    <Guard perm="mobile_app.manage">
      <PageHeader
        title={t("Mobile app downloads")}
        description={t("The PatoPro Android app is not in the Play Store. Upload each new version here; staff download it from the website.")}
        actions={<Button icon={<Upload className="size-4" />} onClick={() => setFormOpen(true)} disabled={!data}>{t("Release a new version")}</Button>}
      />

      {data && (
        <Panel title={t("Version on offer")} className="mb-6">
          {current ? (
            <div className="flex flex-col gap-5 sm:flex-row sm:items-center">
              <QrCode value={data.public_page} size={120} />
              <div className="min-w-0 flex-1 space-y-1">
                <p className="text-lg font-semibold text-ink-900">
                  {t("Version {version}", { version: current.version })} <span className="text-sm font-normal text-ink-500">({t("build {n}", { n: current.build_number })})</span>
                  {current.is_required && <Badge tone="saffron" className="ml-2">{t("Required")}</Badge>}
                </p>
                <p className="text-sm text-ink-600">
                  {[fileSize(current.file_size), current.released_at ? date(current.released_at) : "", t("{n} downloads", { n: current.downloads })].filter(Boolean).join(" · ")}
                </p>
                <p className="text-sm text-ink-600">{t("Share this page with staff, or let them scan the code:")}</p>
                <div className="flex max-w-md items-center gap-2">
                  <input ref={linkRef} readOnly value={data.public_page} className="min-w-0 flex-1 rounded-md bg-ink-50 px-2 py-1.5 font-mono text-xs text-ink-700 ring-1 ring-ink-200" />
                  <Button size="sm" variant="secondary" icon={<Copy className="size-4" />} onClick={copyLink}>{t("Copy")}</Button>
                  <a href="/app" target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline"><ExternalLink className="size-4" />{t("Open")}</a>
                </div>
              </div>
            </div>
          ) : (
            <EmptyState
              icon={<Smartphone className="size-6" />}
              title={t("No app version offered yet")}
              message={t("Build the APK with “eas build -p android --profile production”, then upload it here. The download buttons appear on the login page and shop once a version is published.")}
              action={<Button icon={<Upload className="size-4" />} onClick={() => setFormOpen(true)}>{t("Release a new version")}</Button>}
            />
          )}
        </Panel>
      )}

      <Panel title={t("All versions")} padded={false}>
        {q.isLoading ? (
          <SkeletonTable rows={3} columns={6} />
        ) : releases.length === 0 ? (
          <p className="px-5 py-6 text-sm text-ink-500">{t("No versions uploaded yet.")}</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-500">
                <tr>
                  <th className="px-4 py-2">{t("Version")}</th>
                  <th className="px-4 py-2">{t("File")}</th>
                  <th className="px-4 py-2">{t("Uploaded")}</th>
                  <th className="px-4 py-2 text-right">{t("Downloads")}</th>
                  <th className="px-4 py-2">{t("Status")}</th>
                  <th className="px-4 py-2" />
                </tr>
              </thead>
              <tbody className="divide-y divide-ink-100">
                {releases.map((r) => (
                  <tr key={r.id} className="align-top">
                    <td className="px-4 py-3">
                      <p className="font-medium text-ink-900">{r.version} <span className="text-xs font-normal text-ink-500">#{r.build_number}</span></p>
                      {r.notes && <p className="mt-0.5 line-clamp-2 max-w-xs text-xs text-ink-500">{r.notes}</p>}
                    </td>
                    <td className="px-4 py-3 text-ink-600">{r.source === "upload" ? fileSize(r.file_size) : <span className="inline-flex items-center gap-1"><Link2 className="size-3.5" />{t("Link")}</span>}</td>
                    <td className="px-4 py-3 text-ink-600">{date(r.created_at)}</td>
                    <td className="num px-4 py-3 text-right text-ink-700">{r.downloads}</td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-1">
                        {r.is_current ? <Badge tone="green">{t("On offer")}</Badge> : r.is_published ? <Badge tone="blue">{t("Published")}</Badge> : <Badge tone="neutral">{t("Draft")}</Badge>}
                        {r.is_required && <Badge tone="saffron">{t("Required")}</Badge>}
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap justify-end gap-1">
                        <Button size="sm" variant="secondary" loading={update.isPending && update.variables?.id === r.id && "is_published" in (update.variables ?? {})} onClick={() => update.mutate({ id: r.id, is_published: !r.is_published })}>
                          {r.is_published ? t("Unpublish") : t("Publish")}
                        </Button>
                        <Button size="sm" variant="secondary" onClick={() => update.mutate({ id: r.id, is_required: !r.is_required })}>
                          {r.is_required ? t("Make optional") : t("Make required")}
                        </Button>
                        {r.source === "upload" && r.is_current && (
                          <a href={r.download_url} className="inline-flex items-center rounded-md px-2 text-ink-500 hover:text-ink-900" title={t("Download")} aria-label={t("Download")}><Download className="size-4" /></a>
                        )}
                        <button type="button" className="rounded-md px-2 text-ink-400 hover:text-ember-700" title={t("Delete")} aria-label={t("Delete")} onClick={() => setDeleting(r)}><Trash2 className="size-4" /></button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>

      <NewReleaseForm open={formOpen} onClose={() => setFormOpen(false)} nextBuild={nextBuild} maxMb={data?.max_mb ?? 300} />
      <ConfirmDialog
        open={Boolean(deleting)}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && remove.mutate(deleting.id)}
        loading={remove.isPending}
        title={t("Remove version {version}?", { version: deleting?.version ?? "" })}
        message={t("It will no longer be offered for download. If it is the version on offer, the previous published version is offered instead.")}
        confirmLabel={t("Remove")}
      />
    </Guard>
  );
}
