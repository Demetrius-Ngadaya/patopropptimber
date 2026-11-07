"use client";

import { useRef, useState } from "react";
import { Bell, FileText, Paperclip, Pin, PinOff, Plus, Trash2, Users, X } from "lucide-react";
import { api, type ApiResponse, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { cn, dateTime } from "@/lib/format";
import { useAction, useApi, useDebounced, useUrlParam } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Button, ConfirmDialog, EmptyState, Field, Input, Modal, PageHeader, SearchInput, Select, Spinner, Tabs, Textarea } from "@/components/ui";

type Member = { id: number; user_id: number; access: "owner" | "edit" | "view"; pinned_at: string | null; user?: { id: number; name: string } };
type Attachment = { id: number; original_name: string; mime: string; size: number };
type Note = {
  id: number; user_id: number; title: string; body: string | null; color: string; remind_at: string | null; updated_at: string;
  owner?: { id: number; name: string }; members: Member[]; attachments: Attachment[]; my_access: Member["access"]; my_pinned_at: string | null;
};
type Person = { id: number; name: string; role: string | null };
type Filter = "all" | "mine" | "shared" | "pinned" | "reminders";

const COLORS: Record<string, string> = {
  yellow: "bg-amber-50 ring-amber-200 dark:bg-amber-950/40", green: "bg-emerald-50 ring-emerald-200 dark:bg-emerald-950/40", blue: "bg-sky-50 ring-sky-200 dark:bg-sky-950/40",
  pink: "bg-pink-50 ring-pink-200 dark:bg-pink-950/40", purple: "bg-violet-50 ring-violet-200 dark:bg-violet-950/40", gray: "bg-ink-50 ring-ink-200",
};
const SWATCH: Record<string, string> = { yellow: "bg-amber-300", green: "bg-emerald-400", blue: "bg-sky-400", pink: "bg-pink-400", purple: "bg-violet-400", gray: "bg-ink-300" };
const fileUrl = (id: number) => `/api/backend/admin/notes/attachments/${id}`;
/** "2026-06-10T08:30:00+03:00" → "2026-06-10T08:30" in the browser's time, for <input type="datetime-local">. */
const toLocalInput = (iso: string) => {
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

/** Notes for remembering things: private unless shared, pinned to the top, with files and reminders. */
export default function NotesPage() {
  const t = useT();
  const [filter, setFilter] = useState<Filter>("all");
  const [search, setSearch] = useState("");
  const [open, setOpen] = useState<number | "new" | null>(null);
  useUrlParam("open", (v) => setOpen(Number(v)));
  const list = useApi<Paginated<Note>>("admin/notes", { filter, search: useDebounced(search), per_page: 60 });
  const pin = useAction((n: Note) => api.post(`admin/notes/${n.id}/pin`, { pinned: !n.my_pinned_at }), { invalidate: [["admin/notes"]], silent: true });
  const notes = list.data?.data ?? [];
  const pinned = notes.filter((n) => n.my_pinned_at);
  const others = notes.filter((n) => !n.my_pinned_at);

  const card = (n: Note) => (
    <article key={n.id} className={cn("group relative flex min-h-36 cursor-pointer flex-col rounded-xl p-4 ring-1 transition-shadow hover:shadow-md", COLORS[n.color] ?? COLORS.yellow)}
      onClick={() => setOpen(n.id)} onKeyDown={(e) => e.key === "Enter" && setOpen(n.id)} tabIndex={0} role="button" aria-label={n.title}>
      <button type="button" onClick={(e) => { e.stopPropagation(); pin.mutate(n); }} aria-label={n.my_pinned_at ? t("Unpin") : t("Pin to the top")}
        className={cn("absolute right-2 top-2 rounded-md p-1.5 hover:bg-black/5", n.my_pinned_at ? "text-ink-800" : "text-ink-400 opacity-0 group-hover:opacity-100 focus:opacity-100")}>
        {n.my_pinned_at ? <Pin className="size-4 fill-current" /> : <Pin className="size-4" />}
      </button>
      <h3 className="pr-7 font-semibold text-ink-900">{n.title}</h3>
      {n.body && <p className="mt-1 line-clamp-6 whitespace-pre-line text-sm text-ink-700">{n.body}</p>}
      <div className="mt-auto flex flex-wrap items-center gap-2 pt-3 text-xs text-ink-500">
        {n.remind_at && <span className="inline-flex items-center gap-1"><Bell className="size-3.5" />{dateTime(n.remind_at)}</span>}
        {n.attachments.length > 0 && <span className="inline-flex items-center gap-1"><Paperclip className="size-3.5" />{n.attachments.length}</span>}
        {n.members.length > 1 && <span className="inline-flex items-center gap-1"><Users className="size-3.5" />{n.members.length - 1}</span>}
        {n.my_access !== "owner" && <span>{t("From {name}", { name: n.owner?.name ?? "" })}</span>}
      </div>
    </article>
  );

  return (
    <>
      <PageHeader title={t("My notes")} description={t("Write things down to remember them. Notes are private unless you share them; pin the ones you need to see first.")}
        actions={<Button icon={<Plus className="size-4" />} onClick={() => setOpen("new")}>{t("New note")}</Button>} />
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <Tabs<Filter> value={filter} onChange={setFilter} tabs={[
          { value: "all", label: t("All") }, { value: "mine", label: t("Mine") }, { value: "shared", label: t("Shared with me") },
          { value: "pinned", label: t("Pinned") }, { value: "reminders", label: t("With a reminder") },
        ]} />
        <SearchInput value={search} onChange={setSearch} placeholder={t("Search notes")} className="w-full max-w-xs" />
      </div>
      {list.isLoading && <Spinner variant="cards" />}
      {!list.isLoading && notes.length === 0 && <EmptyState icon={<FileText className="size-10" />} title={t("No notes here")} action={<Button icon={<Plus className="size-4" />} onClick={() => setOpen("new")}>{t("Write a note")}</Button>} />}
      {pinned.length > 0 && filter !== "pinned" && (
        <section className="mb-6">
          <h2 className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-ink-500"><Pin className="size-3.5" />{t("Pinned")}</h2>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">{pinned.map(card)}</div>
        </section>
      )}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">{(filter === "pinned" ? pinned : others).map(card)}</div>
      {open !== null && <NoteEditor id={open === "new" ? null : open} onClose={() => setOpen(null)} onCreated={(id) => setOpen(id)} />}
    </>
  );
}

function NoteEditor({ id, onClose, onCreated }: { id: number | null; onClose: () => void; onCreated: (id: number) => void }) {
  const t = useT();
  const { user } = useAuth();
  const q = useApi<ApiResponse<Note>>(id ? `admin/notes/${id}` : null, undefined, { keepPrevious: false });
  const people = useApi<ApiResponse<Person[]>>("admin/notes/people");
  const note = q.data?.data;
  const isOwner = !note || note.user_id === user?.id;
  const canEdit = !note || note.my_access === "owner" || note.my_access === "edit";
  const [f, setF] = useState({ title: "", body: "", color: "yellow", remind_at: "" });
  const [shares, setShares] = useState<Array<{ user_id: number; access: "view" | "edit" }>>([]);
  const [loadedId, setLoadedId] = useState<number | null>(null);
  const [deleting, setDeleting] = useState(false);
  const fileInput = useRef<HTMLInputElement>(null);

  // Fill the form once the note arrives (state set while rendering: React's way to reset on new data).
  if (note && loadedId !== note.id) {
    setLoadedId(note.id);
    setF({ title: note.title, body: note.body ?? "", color: note.color, remind_at: note.remind_at ? toLocalInput(note.remind_at) : "" });
    setShares(note.members.filter((m) => m.access !== "owner").map((m) => ({ user_id: m.user_id, access: m.access as "view" | "edit" })));
  }

  const body = () => ({ ...f, remind_at: f.remind_at || null, body: f.body || null, ...(isOwner ? { shares } : {}) });
  const save = useAction(() => id ? api.put<ApiResponse<Note>>(`admin/notes/${id}`, body()) : api.post<ApiResponse<Note>>("admin/notes", body()), {
    invalidate: [["admin/notes"], id ? [`admin/notes/${id}`] : ["admin/notes"]],
    onSuccess: (r) => (id ? onClose() : onCreated(r.data.id)),
  });
  const upload = useAction((file: File) => { const form = new FormData(); form.append("file", file); return api.post(`admin/notes/${id}/attachments`, form); }, { invalidate: [[`admin/notes/${id}`], ["admin/notes"]] });
  const detach = useAction((aid: number) => api.del(`admin/notes/attachments/${aid}`), { invalidate: [[`admin/notes/${id}`], ["admin/notes"]] });
  const remove = useAction(() => api.del(`admin/notes/${id}`), { invalidate: [["admin/notes"]], onSuccess: onClose });
  const others = (people.data?.data ?? []).filter((p) => !shares.some((s) => s.user_id === p.id));

  if (id && q.isLoading) return <Modal open onClose={onClose} title={t("Note")}><Spinner variant="compact" /></Modal>;

  return (
    <Modal open onClose={onClose} size="lg" title={id ? (canEdit ? t("Edit note") : t("Note")) : t("New note")}
      description={note && !isOwner ? t("Shared by {name} · you can {access}", { name: note.owner?.name ?? "", access: note.my_access === "edit" ? t("change it") : t("read it") }) : undefined}
      footer={<>
        {id && <Button variant="ghost" className="mr-auto text-ember-600" icon={<Trash2 className="size-4" />} onClick={() => setDeleting(true)}>{isOwner ? t("Delete") : t("Remove from my list")}</Button>}
        <Button variant="ghost" onClick={onClose}>{t("Close")}</Button>
        {canEdit && <Button loading={save.isPending} disabled={!f.title.trim()} onClick={() => save.mutate(undefined)}>{id ? t("Save") : t("Save and continue")}</Button>}
      </>}>
      <div className="space-y-4">
        <Field label={t("Title")} required>{(p) => <Input {...p} value={f.title} disabled={!canEdit} onChange={(e) => setF({ ...f, title: e.target.value })} placeholder={t("e.g. Call the supplier about sugar")} />}</Field>
        <Field label={t("Note")}>{(p) => <Textarea {...p} rows={8} value={f.body} disabled={!canEdit} onChange={(e) => setF({ ...f, body: e.target.value })} />}</Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <fieldset>
            <legend className="mb-1.5 text-[13px] font-medium text-ink-700">{t("Colour")}</legend>
            <div className="flex gap-2">
              {Object.keys(SWATCH).map((c) => (
                <button key={c} type="button" disabled={!canEdit} onClick={() => setF({ ...f, color: c })} aria-label={c} aria-pressed={f.color === c}
                  className={cn("size-7 rounded-full ring-2 ring-offset-2 ring-offset-surface", SWATCH[c], f.color === c ? "ring-brand-600" : "ring-transparent")} />
              ))}
            </div>
          </fieldset>
          <Field label={t("Remind me on")} hint={t("You get it in the bell.")}>{(p) => <Input {...p} type="datetime-local" value={f.remind_at} disabled={!canEdit} onChange={(e) => setF({ ...f, remind_at: e.target.value })} />}</Field>
        </div>

        {id ? (
          <div>
            <p className="mb-1.5 text-[13px] font-medium text-ink-700">{t("Files (PDF and pictures)")}</p>
            <ul className="space-y-1.5">
              {note?.attachments.map((a) => (
                <li key={a.id} className="flex items-center gap-2 rounded-md bg-ink-50 px-2 py-1.5 text-sm">
                  {a.mime.startsWith("image/")
                    // eslint-disable-next-line @next/next/no-img-element -- served by the API with the session cookie
                    ? <img src={fileUrl(a.id)} alt="" className="size-10 rounded object-cover" />
                    : <FileText className="size-5 text-ember-600" />}
                  <a href={fileUrl(a.id)} target="_blank" rel="noreferrer" className="min-w-0 flex-1 truncate text-brand-700 hover:underline">{a.original_name}</a>
                  <span className="text-xs text-ink-500">{Math.max(1, Math.round(a.size / 1024))} KB</span>
                  {canEdit && <button type="button" onClick={() => detach.mutate(a.id)} className="rounded p-1 text-ink-400 hover:text-ember-600" aria-label={t("Remove")}><X className="size-4" /></button>}
                </li>
              ))}
            </ul>
            {canEdit && (
              <>
                <input ref={fileInput} type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" className="hidden" onChange={(e) => { const file = e.target.files?.[0]; if (file) upload.mutate(file); e.target.value = ""; }} />
                <Button size="sm" variant="secondary" className="mt-2" icon={<Paperclip className="size-4" />} loading={upload.isPending} onClick={() => fileInput.current?.click()}>{t("Attach a file")}</Button>
              </>
            )}
          </div>
        ) : <p className="text-xs text-ink-500">{t("Save the note first, then attach PDFs and pictures.")}</p>}

        {isOwner && (
          <div>
            <p className="mb-1.5 text-[13px] font-medium text-ink-700">{t("Shared with")}</p>
            {shares.length === 0 && <p className="text-sm text-ink-500">{t("Only you can see this note.")}</p>}
            <ul className="space-y-1.5">
              {shares.map((s) => {
                const person = people.data?.data.find((p) => p.id === s.user_id) ?? note?.members.find((m) => m.user_id === s.user_id)?.user;
                return (
                  <li key={s.user_id} className="flex items-center gap-2 text-sm">
                    <span className="min-w-0 flex-1 truncate">{person?.name ?? `#${s.user_id}`}</span>
                    <div className="w-52 shrink-0">
                      <Select value={s.access} onChange={(e) => setShares(shares.map((x) => (x.user_id === s.user_id ? { ...x, access: e.target.value as "view" | "edit" } : x)))} className="h-8" aria-label={t("Access")}>
                        <option value="view">{t("Can read")}</option><option value="edit">{t("Can change")}</option>
                      </Select>
                    </div>
                    <button type="button" onClick={() => setShares(shares.filter((x) => x.user_id !== s.user_id))} className="rounded p-1 text-ink-400 hover:text-ember-600" aria-label={t("Stop sharing")}><X className="size-4" /></button>
                  </li>
                );
              })}
            </ul>
            {others.length > 0 && (
              <Select value="" onChange={(e) => e.target.value && setShares([...shares, { user_id: Number(e.target.value), access: "view" }])} className="mt-2 h-9" aria-label={t("Share with")}>
                <option value="">{t("+ Share with a colleague")}</option>
                {others.map((p) => <option key={p.id} value={p.id}>{p.name}{p.role ? ` (${p.role})` : ""}</option>)}
              </Select>
            )}
          </div>
        )}
        {note && !isOwner && note.my_pinned_at && <p className="flex items-center gap-1 text-xs text-ink-500"><PinOff className="size-3.5" />{t("Pinned on your board.")}</p>}
      </div>
      <ConfirmDialog open={deleting} onClose={() => setDeleting(false)} title={isOwner ? t("Delete this note?") : t("Remove from your list?")}
        message={isOwner ? t("It is deleted for everyone it was shared with.") : t("The owner keeps it.")} confirmLabel={isOwner ? t("Delete") : t("Remove")} loading={remove.isPending} onConfirm={() => remove.mutate(undefined)} />
    </Modal>
  );
}
