"use client";

import { useEffect, useState } from "react";
import { ArrowDown, ArrowUp, ImagePlus, Pencil, RotateCcw, Trash2 } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { cn } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, Checkbox, ConfirmDialog, EmptyState, Field, Input, Modal, PageHeader, Panel, Spinner, Textarea } from "@/components/ui";

type Slide = { id: number; title: string | null; caption: string | null; image_url: string; image_small_url?: string | null; sort_order: number; is_active: boolean; show_on_login: boolean; show_on_shop: boolean; deleted_at: string | null };

function SlideForm({ slide, open, onClose }: { slide: Slide | null; open: boolean; onClose: () => void }) {
  const t = useT();
  const [title, setTitle] = useState("");
  const [caption, setCaption] = useState("");
  const [active, setActive] = useState(true);
  const [onLogin, setOnLogin] = useState(true);
  const [onShop, setOnShop] = useState(false);
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setTitle(slide?.title ?? "");
    setCaption(slide?.caption ?? "");
    setActive(slide?.is_active ?? true);
    setOnLogin(slide?.show_on_login ?? true);
    setOnShop(slide?.show_on_shop ?? false);
    setFile(null);
    setPreview(slide?.image_url ?? null);
  }, [open, slide]);

  useEffect(() => {
    if (!file) return;
    const url = URL.createObjectURL(file);
    setPreview(url);
    return () => URL.revokeObjectURL(url);
  }, [file]);

  const save = useAction(
    () => {
      const form = new FormData();
      if (file) form.append("image", file);
      form.append("title", title);
      form.append("caption", caption);
      form.append("is_active", active ? "1" : "0");
      form.append("show_on_login", onLogin ? "1" : "0");
      form.append("show_on_shop", onShop ? "1" : "0");
      return slide ? api.putForm(`admin/login-slides/${slide.id}`, form) : api.post("admin/login-slides", form);
    },
    { invalidate: [["admin/login-slides"], ["public/login-slides"]], onSuccess: onClose },
  );
  const errors = fieldErrors(save.error);

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={slide ? t("Edit picture") : t("Add a picture")}
      description={t("Best: a landscape photo about 1920 × 1080 pixels (16:9). JPG, PNG or WebP up to 5 MB. It is made smaller automatically, so it loads fast.")}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>{t("Cancel")}</Button>
          <Button loading={save.isPending} disabled={!slide && !file} onClick={() => save.mutate(undefined)}>{t("Save")}</Button>
        </>
      }
    >
      <div className="space-y-4">
        <label className="group relative block aspect-[16/10] cursor-pointer overflow-hidden rounded-lg bg-ink-100 ring-1 ring-ink-200">
          {preview ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={preview} alt="" className="size-full object-cover" />
          ) : (
            <span className="absolute inset-0 grid place-items-center text-sm text-ink-500"><span className="flex flex-col items-center gap-2"><ImagePlus className="size-8" />{t("Choose a picture")}</span></span>
          )}
          <span className="absolute inset-x-0 bottom-0 bg-black/50 px-3 py-1.5 text-center text-xs text-white opacity-0 transition-opacity group-hover:opacity-100">{t("Click to choose another picture")}</span>
          <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        </label>
        {errors.image && <p className="text-sm text-ember-700">{errors.image}</p>}
        <Field label={t("Title (optional)")} error={errors.title} hint={t("Shown on the picture. Leave empty for a picture only.")}>
          {(p) => <Input {...p} value={title} onChange={(e) => setTitle(e.target.value)} maxLength={120} />}
        </Field>
        <Field label={t("Caption (optional)")} error={errors.caption}>
          {(p) => <Textarea {...p} rows={2} value={caption} onChange={(e) => setCaption(e.target.value)} maxLength={300} />}
        </Field>
        <div className="space-y-2 rounded-lg bg-ink-50 p-3">
          <p className="text-sm font-medium text-ink-800">{t("Where to show this picture")}</p>
          <Checkbox label={t("Beside the login form")} checked={onLogin} onChange={(e) => setOnLogin(e.target.checked)} />
          <Checkbox label={t("At the top of the online shop")} checked={onShop} onChange={(e) => setOnShop(e.target.checked)} />
          <p className="text-xs text-ink-500">{t("Tick both to use the same picture in both places. The shop shows pictures only when “show my pictures” is on in Settings → Online shop.")}</p>
        </div>
        <Checkbox label={t("Picture is switched on")} checked={active} onChange={(e) => setActive(e.target.checked)} />
      </div>
    </Modal>
  );
}

export default function LoginSlidesPage() {
  const t = useT();
  const { can } = useAuth();
  const [trashed, setTrashed] = useState(false);
  const [editing, setEditing] = useState<Slide | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [deleting, setDeleting] = useState<Slide | null>(null);
  const q = useApi<ApiResponse<Slide[]>>("admin/login-slides", { trashed: trashed ? "only" : undefined });
  const slides = q.data?.data ?? [];

  const invalidate = [["admin/login-slides"], ["public/login-slides"]];
  const reorder = useAction((ids: number[]) => api.post("admin/login-slides/reorder", { ids }), { invalidate, silent: true });
  const remove = useAction((id: number) => api.del(`admin/login-slides/${id}`), { invalidate, onSuccess: () => setDeleting(null) });
  const restore = useAction((id: number) => api.post(`admin/login-slides/${id}/restore`), { invalidate });

  const move = (index: number, by: number) => {
    const ids = slides.map((s) => s.id);
    const target = index + by;
    if (target < 0 || target >= ids.length) return;
    [ids[index], ids[target]] = [ids[target], ids[index]];
    reorder.mutate(ids);
  };

  return (
    <Guard perm="login_slides.view">
      <PageHeader
        title={t("Pictures: login & shop")}
        description={t("Pictures that slide beside the login form and at the top of the online shop. Choose for each picture where it shows.")}
        actions={can("login_slides.create") && !trashed && <Button icon={<ImagePlus className="size-4" />} onClick={() => { setEditing(null); setFormOpen(true); }}>{t("Add picture")}</Button>}
      />
      <div className="mb-4 flex items-center gap-4">
        {can("login_slides.restore") && <Checkbox label={t("Show deleted")} checked={trashed} onChange={(e) => setTrashed(e.target.checked)} />}
      </div>

      {q.isLoading ? <Spinner /> : !slides.length ? (
        <Panel>
          <EmptyState icon={<ImagePlus className="size-9" />} title={trashed ? t("No deleted pictures") : t("No pictures yet")} message={trashed ? undefined : t("Without pictures, the login page shows a calm coloured background and the shop shows its heading text.")} />
        </Panel>
      ) : (
        <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {slides.map((s, i) => (
            <li key={s.id} className="overflow-hidden rounded-[var(--radius-panel)] bg-surface ring-1 ring-ink-200/70">
              <div className="relative aspect-[16/10] bg-ink-100">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src={s.image_small_url ?? s.image_url} alt={s.title ?? ""} loading="lazy" decoding="async" className={cn("size-full object-cover", (!s.is_active || trashed) && "opacity-50 grayscale")} />
                <span className="absolute left-2 top-2 flex gap-1">
                  {!s.is_active ? <Badge tone="neutral">{t("Hidden")}</Badge> : (
                    <>
                      {s.show_on_login && <Badge tone="green">{t("Login")}</Badge>}
                      {s.show_on_shop && <Badge tone="blue">{t("Shop")}</Badge>}
                      {!s.show_on_login && !s.show_on_shop && <Badge tone="neutral">{t("Not placed")}</Badge>}
                    </>
                  )}
                </span>
                <span className="num absolute right-2 top-2 rounded-full bg-black/55 px-2 py-0.5 text-xs text-white">#{i + 1}</span>
              </div>
              <div className="flex items-start justify-between gap-2 p-3">
                <div className="min-w-0">
                  <p className="truncate text-sm font-semibold text-ink-900">{s.title || t("Untitled picture")}</p>
                  {s.caption && <p className="line-clamp-2 text-xs text-ink-500">{s.caption}</p>}
                </div>
                <div className="flex shrink-0 gap-1">
                  {trashed ? (
                    <Button size="sm" variant="secondary" icon={<RotateCcw className="size-3.5" />} loading={restore.isPending && restore.variables === s.id} onClick={() => restore.mutate(s.id)}>{t("Restore")}</Button>
                  ) : (
                    <>
                      {can("login_slides.update") && (
                        <>
                          <Button size="sm" variant="ghost" aria-label={t("Move earlier")} disabled={i === 0 || reorder.isPending} onClick={() => move(i, -1)}><ArrowUp className="size-3.5" /></Button>
                          <Button size="sm" variant="ghost" aria-label={t("Move later")} disabled={i === slides.length - 1 || reorder.isPending} onClick={() => move(i, 1)}><ArrowDown className="size-3.5" /></Button>
                          <Button size="sm" variant="ghost" aria-label={t("Edit")} onClick={() => { setEditing(s); setFormOpen(true); }}><Pencil className="size-3.5" /></Button>
                        </>
                      )}
                      {can("login_slides.delete") && <Button size="sm" variant="ghost" aria-label={t("Delete")} onClick={() => setDeleting(s)}><Trash2 className="size-3.5 text-ember-600" /></Button>}
                    </>
                  )}
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}

      <SlideForm slide={editing} open={formOpen} onClose={() => setFormOpen(false)} />
      <ConfirmDialog
        open={Boolean(deleting)}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && remove.mutate(deleting.id)}
        title={t("Remove this picture?")}
        message={t("It will no longer show on the login page or in the shop. You can restore it from the deleted list.")}
        confirmLabel={t("Remove picture")}
        loading={remove.isPending}
      />
    </Guard>
  );
}
