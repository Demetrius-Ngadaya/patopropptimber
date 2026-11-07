"use client";

import { useState } from "react";
import { ChevronLeft, ChevronRight, Clock, Plus, Settings2, Trash2 } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { cn, dateTime, formatDate } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, Checkbox, ConfirmDialog, Field, Input, Modal, PageHeader, Select, Spinner, Textarea } from "@/components/ui";
import { hhmm, SHIFT_COLOR_LABELS, SHIFT_COLORS, SHIFT_STATUS_LABELS, SHIFT_STATUS_TONES, WEEKDAYS, type Shift, type ShiftType } from "@/features/shifts/types";

type Person = { id: number; name: string; role: string | null };

const pad = (n: number) => String(n).padStart(2, "0");
const ymd = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const mondayOf = (d: Date) => { const x = new Date(d); x.setHours(0, 0, 0, 0); x.setDate(x.getDate() - ((x.getDay() + 6) % 7)); return x; };
const addDays = (d: Date, n: number) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
const localInput = (iso: string) => { const d = new Date(iso); return `${ymd(d)}T${pad(d.getHours())}:${pad(d.getMinutes())}`; };

/** The rota: who works when this week. Managers plan and change shifts here. */
export default function ShiftRotaPage() {
  const t = useT();
  const { can } = useAuth();
  const [monday, setMonday] = useState(() => mondayOf(new Date()));
  const days = Array.from({ length: 7 }, (_, i) => addDays(monday, i));
  const q = useApi<ApiResponse<{ shifts: Shift[] }>>("admin/shifts", { from: ymd(days[0]), to: ymd(days[6]) });
  const types = useApi<ApiResponse<ShiftType[]>>("admin/shifts/types");
  const people = useApi<ApiResponse<Person[]>>("admin/shifts/staff");
  const [planning, setPlanning] = useState(false);
  const [editing, setEditing] = useState<Shift | null>(null);
  const [typesOpen, setTypesOpen] = useState(false);
  const manage = can("shifts.manage");
  const shifts = q.data?.data.shifts ?? [];
  const todayYmd = ymd(new Date());

  return (
    <Guard perm={["shifts.view", "shifts.manage"]}>
      <PageHeader
        title={t("Shift rota")}
        description={t("Who works when. Staff start and end their own shifts, ask colleagues to cover, and hand over at the end.")}
        actions={manage && <><Button variant="secondary" icon={<Settings2 className="size-4" />} onClick={() => setTypesOpen(true)}>{t("Shift types")}</Button><Button icon={<Plus className="size-4" />} onClick={() => setPlanning(true)}>{t("Plan shifts")}</Button></>}
      />
      <div className="mb-4 flex items-center gap-2">
        <Button variant="secondary" size="sm" aria-label={t("Previous week")} onClick={() => setMonday(addDays(monday, -7))}><ChevronLeft className="size-4" /></Button>
        <Button variant="secondary" size="sm" onClick={() => setMonday(mondayOf(new Date()))}>{t("This week")}</Button>
        <Button variant="secondary" size="sm" aria-label={t("Next week")} onClick={() => setMonday(addDays(monday, 7))}><ChevronRight className="size-4" /></Button>
        <span className="ml-2 text-sm font-medium text-ink-700">{formatDate(days[0], { day: "numeric", month: "short" })} – {formatDate(days[6], { day: "numeric", month: "short", year: "numeric" })}</span>
      </div>
      {q.isLoading ? <Spinner /> : (
        <div className="grid gap-2 md:grid-cols-7">
          {days.map((d) => {
            const key = ymd(d);
            const list = shifts.filter((s) => ymd(new Date(s.starts_at)) === key);
            return (
              <section key={key} className={cn("min-h-40 rounded-lg bg-surface p-2 ring-1 ring-ink-200/70", key === todayYmd && "ring-2 ring-brand-500")}>
                <h2 className="mb-2 px-1 text-xs font-semibold uppercase tracking-wide text-ink-500">{formatDate(d, { weekday: "short", day: "numeric" })}</h2>
                <ul className="space-y-1.5">
                  {list.map((s) => {
                    const pending = s.delegations?.some((x) => x.status === "pending");
                    return (
                      <li key={s.id}>
                        <button type="button" disabled={!manage} onClick={() => setEditing(s)}
                          className={cn("w-full rounded-md px-2 py-1.5 text-left text-xs ring-1", SHIFT_COLORS[s.type?.color ?? "gray"] ?? SHIFT_COLORS.gray, s.status === "cancelled" && "opacity-50 line-through", manage && "hover:brightness-95")}>
                          <span className="block truncate font-semibold">{s.user?.name}</span>
                          <span className="flex items-center gap-1"><Clock className="size-3" />{hhmm(s.starts_at)}–{hhmm(s.ends_at)}{s.type ? ` · ${s.type.name}` : ""}</span>
                          {s.status !== "scheduled" && <Badge tone={SHIFT_STATUS_TONES[s.status]} className="mt-1">{t(SHIFT_STATUS_LABELS[s.status])}</Badge>}
                          {pending && <span className="mt-1 block text-[11px] text-saffron-800">{t("Cover requested")}</span>}
                        </button>
                      </li>
                    );
                  })}
                  {!list.length && <li className="px-1 text-xs text-ink-400">—</li>}
                </ul>
              </section>
            );
          })}
        </div>
      )}
      {planning && <PlanDialog types={types.data?.data ?? []} people={people.data?.data ?? []} start={ymd(days[0])} onClose={() => setPlanning(false)} />}
      {editing && <EditDialog shift={editing} people={people.data?.data ?? []} onClose={() => setEditing(null)} />}
      {typesOpen && <TypesDialog types={types.data?.data ?? []} onClose={() => setTypesOpen(false)} />}
    </Guard>
  );
}

function PlanDialog({ types, people, start, onClose }: { types: ShiftType[]; people: Person[]; start: string; onClose: () => void }) {
  const t = useT();
  const [userIds, setUserIds] = useState<number[]>([]);
  const [typeId, setTypeId] = useState(types[0] ? String(types[0].id) : "");
  const [times, setTimes] = useState({ start: "08:00", end: "16:00" });
  const [from, setFrom] = useState(start);
  const [to, setTo] = useState(ymd(addDays(new Date(start), 6)));
  const [weekdays, setWeekdays] = useState<number[]>([0, 1, 2, 3, 4, 5, 6]);
  const [notes, setNotes] = useState("");
  const [result, setResult] = useState<string[] | null>(null);
  const plan = useAction(() => api.post<ApiResponse<{ created: number; skipped: string[] }>>("admin/shifts", {
    user_ids: userIds, shift_type_id: typeId ? Number(typeId) : null, start_time: typeId ? null : times.start, end_time: typeId ? null : times.end,
    date_from: from, date_to: to, weekdays, notes: notes || null,
  }), { invalidate: [["admin/shifts"]], onSuccess: (r) => (r.data.skipped.length ? setResult(r.data.skipped) : onClose()) });
  const e = fieldErrors(plan.error);
  const toggle = <T,>(list: T[], v: T) => (list.includes(v) ? list.filter((x) => x !== v) : [...list, v]);

  return (
    <Modal open onClose={onClose} size="lg" title={t("Plan shifts")}
      footer={<><Button variant="ghost" onClick={onClose}>{t("Close")}</Button><Button loading={plan.isPending} disabled={!userIds.length} onClick={() => plan.mutate(undefined)}>{t("Plan")}</Button></>}>
      <div className="space-y-4">
        <fieldset>
          <legend className="mb-1.5 text-[13px] font-medium text-ink-700">{t("People")}</legend>
          <div className="grid max-h-44 gap-1.5 overflow-y-auto rounded-md p-2 ring-1 ring-ink-200 sm:grid-cols-2">
            {people.map((p) => <Checkbox key={p.id} label={<span>{p.name} <span className="text-xs text-ink-500">{p.role}</span></span>} checked={userIds.includes(p.id)} onChange={() => setUserIds(toggle(userIds, p.id))} />)}
          </div>
          {e.user_ids && <p className="mt-1 text-xs text-ember-600">{e.user_ids}</p>}
        </fieldset>
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label={t("Shift")}>{(p) => (
            <Select {...p} value={typeId} onChange={(ev) => setTypeId(ev.target.value)}>
              {types.filter((x) => x.is_active).map((x) => <option key={x.id} value={x.id}>{x.name} ({x.start_time.slice(0, 5)}–{x.end_time.slice(0, 5)})</option>)}
              <option value="">{t("Other times")}</option>
            </Select>
          )}</Field>
          {!typeId && <>
            <Field label={t("Starts")} error={e.start_time}>{(p) => <Input {...p} type="time" value={times.start} onChange={(ev) => setTimes({ ...times, start: ev.target.value })} />}</Field>
            <Field label={t("Ends")} hint={t("Earlier than the start = the next morning")} error={e.end_time}>{(p) => <Input {...p} type="time" value={times.end} onChange={(ev) => setTimes({ ...times, end: ev.target.value })} />}</Field>
          </>}
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t("From")} error={e.date_from}>{(p) => <Input {...p} type="date" value={from} onChange={(ev) => setFrom(ev.target.value)} />}</Field>
          <Field label={t("To")} error={e.date_to}>{(p) => <Input {...p} type="date" min={from} value={to} onChange={(ev) => setTo(ev.target.value)} />}</Field>
        </div>
        <fieldset>
          <legend className="mb-1.5 text-[13px] font-medium text-ink-700">{t("On these days")}</legend>
          <div className="flex flex-wrap gap-1.5">
            {[1, 2, 3, 4, 5, 6, 0].map((d) => (
              <button key={d} type="button" aria-pressed={weekdays.includes(d)} onClick={() => setWeekdays(toggle(weekdays, d))}
                className={cn("rounded-full px-3 py-1 text-xs ring-1", weekdays.includes(d) ? "bg-brand-600 text-white ring-brand-600" : "ring-ink-200")}>{t(WEEKDAYS[d]).slice(0, 3)}</button>
            ))}
          </div>
        </fieldset>
        <Field label={t("Note (optional)")}>{(p) => <Input {...p} value={notes} onChange={(ev) => setNotes(ev.target.value)} />}</Field>
        {result && (
          <div className="rounded-md bg-saffron-50 p-3 text-sm text-saffron-800">
            <p className="font-medium">{t("Skipped because the person already works then:")}</p>
            <ul className="mt-1 list-disc pl-5">{result.map((x) => <li key={x}>{x}</li>)}</ul>
          </div>
        )}
      </div>
    </Modal>
  );
}

function EditDialog({ shift, people, onClose }: { shift: Shift; people: Person[]; onClose: () => void }) {
  const t = useT();
  const [f, setF] = useState({ user_id: String(shift.user_id), starts_at: localInput(shift.starts_at), ends_at: localInput(shift.ends_at), notes: shift.notes ?? "" });
  const [cancelling, setCancelling] = useState(false);
  const editable = shift.status === "scheduled" || shift.status === "in_progress";
  const save = useAction(() => api.put(`admin/shifts/${shift.id}`, { ...f, user_id: Number(f.user_id), notes: f.notes || null }), { invalidate: [["admin/shifts"]], onSuccess: onClose });
  const cancel = useAction((reason: string) => api.post(`admin/shifts/${shift.id}/cancel`, { reason }), { invalidate: [["admin/shifts"]], onSuccess: onClose });
  const e = fieldErrors(save.error);
  return (
    <Modal open onClose={onClose} title={t("Shift of {name}", { name: shift.user?.name ?? "" })} description={t(SHIFT_STATUS_LABELS[shift.status])}
      footer={<>
        {editable && <Button variant="ghost" className="mr-auto text-ember-600" icon={<Trash2 className="size-4" />} onClick={() => setCancelling(true)}>{t("Cancel shift")}</Button>}
        <Button variant="ghost" onClick={onClose}>{t("Close")}</Button>
        {editable && <Button loading={save.isPending} onClick={() => save.mutate(undefined)}>{t("Save")}</Button>}
      </>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t("Person")} error={e.user_id} className="sm:col-span-2">{(p) => <Select {...p} disabled={!editable} value={f.user_id} onChange={(ev) => setF({ ...f, user_id: ev.target.value })}>{people.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</Select>}</Field>
        <Field label={t("Starts")} error={e.starts_at}>{(p) => <Input {...p} type="datetime-local" disabled={!editable} value={f.starts_at} onChange={(ev) => setF({ ...f, starts_at: ev.target.value })} />}</Field>
        <Field label={t("Ends")} error={e.ends_at}>{(p) => <Input {...p} type="datetime-local" disabled={!editable} value={f.ends_at} onChange={(ev) => setF({ ...f, ends_at: ev.target.value })} />}</Field>
        <Field label={t("Note")} className="sm:col-span-2">{(p) => <Textarea {...p} rows={2} disabled={!editable} value={f.notes} onChange={(ev) => setF({ ...f, notes: ev.target.value })} />}</Field>
      </div>
      {(shift.started_at || shift.handover || shift.delegations?.length) && (
        <ul className="mt-4 space-y-1 border-t border-ink-100 pt-3 text-xs text-ink-600">
          {shift.started_at && <li>{t("Started at {time}", { time: dateTime(shift.started_at) })}</li>}
          {shift.ended_at && <li>{t("Ended at {time}", { time: dateTime(shift.ended_at) })}</li>}
          {shift.delegations?.map((d) => <li key={d.id}>{t("{from} asked {to} to cover: {reason}", { from: d.from_user?.name ?? "", to: d.to_user?.name ?? "", reason: d.reason })} · {t(d.status === "accepted" ? "Accepted" : d.status === "declined" ? "Declined" : d.status === "pending" ? "Waiting" : "Cancelled")}</li>)}
          {shift.handover && <li>{t("Handed over to {name}", { name: shift.handover.to_user?.name ?? "" })}{shift.handover.notes ? `: ${shift.handover.notes}` : ""}{shift.handover.status === "accepted" ? ` ✓` : ""}</li>}
        </ul>
      )}
      <ConfirmDialog open={cancelling} onClose={() => setCancelling(false)} title={t("Cancel this shift?")} message={t("The person is told.")} confirmLabel={t("Cancel shift")} requireReason loading={cancel.isPending} onConfirm={(r) => cancel.mutate(r)} />
    </Modal>
  );
}

function TypesDialog({ types, onClose }: { types: ShiftType[]; onClose: () => void }) {
  const t = useT();
  const [f, setF] = useState({ name: "", start_time: "08:00", end_time: "16:00", color: "blue" });
  const add = useAction(() => api.post("admin/shifts/types", f), { invalidate: [["admin/shifts/types"]], onSuccess: () => setF({ ...f, name: "" }) });
  const remove = useAction((id: number) => api.del(`admin/shifts/types/${id}`), { invalidate: [["admin/shifts/types"]] });
  const e = fieldErrors(add.error);
  return (
    <Modal open onClose={onClose} title={t("Shift types")} description={t("The usual shifts, e.g. Morning 07:00–15:00 or Night 22:00–06:00.")}>
      <ul className="mb-4 divide-y divide-ink-100">
        {types.map((x) => (
          <li key={x.id} className="flex items-center gap-2 py-2 text-sm">
            <span className={cn("rounded px-2 py-0.5 text-xs ring-1", SHIFT_COLORS[x.color] ?? SHIFT_COLORS.gray)}>{x.name}</span>
            <span className="num flex-1 text-ink-600">{x.start_time.slice(0, 5)} – {x.end_time.slice(0, 5)}</span>
            <Button size="sm" variant="ghost" aria-label={t("Remove")} onClick={() => remove.mutate(x.id)}><Trash2 className="size-4" /></Button>
          </li>
        ))}
        {!types.length && <li className="py-2 text-sm text-ink-500">{t("No shift types yet.")}</li>}
      </ul>
      <div className="grid gap-2 sm:grid-cols-[1fr_auto_auto_auto_auto] sm:items-end">
        <Field label={t("Name")} error={e.name}>{(p) => <Input {...p} value={f.name} onChange={(ev) => setF({ ...f, name: ev.target.value })} placeholder={t("e.g. Morning")} />}</Field>
        <Field label={t("Starts")}>{(p) => <Input {...p} type="time" value={f.start_time} onChange={(ev) => setF({ ...f, start_time: ev.target.value })} />}</Field>
        <Field label={t("Ends")} error={e.end_time}>{(p) => <Input {...p} type="time" value={f.end_time} onChange={(ev) => setF({ ...f, end_time: ev.target.value })} />}</Field>
        <Field label={t("Colour")}>{(p) => <Select {...p} value={f.color} onChange={(ev) => setF({ ...f, color: ev.target.value })}>{Object.keys(SHIFT_COLORS).map((c) => <option key={c} value={c}>{t(SHIFT_COLOR_LABELS[c] ?? c)}</option>)}</Select>}</Field>
        <Button loading={add.isPending} disabled={!f.name.trim()} onClick={() => add.mutate(undefined)}>{t("Add")}</Button>
      </div>
    </Modal>
  );
}
