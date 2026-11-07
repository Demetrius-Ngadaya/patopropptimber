"use client";

import { useState } from "react";
import { ArrowRightLeft, CalendarClock, Check, HandHelping, LogIn, LogOut, X } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { cn, date, dateTime, formatDate, money } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, Checkbox, EmptyState, Field, Input, Modal, PageHeader, Panel, Select, Spinner, Textarea } from "@/components/ui";
import { hhmm, SHIFT_STATUS_LABELS, SHIFT_STATUS_TONES, type Delegation, type Handover, type Shift } from "@/features/shifts/types";

type Mine = { current: Shift | null; next_shift: Shift | null; upcoming: Shift[]; recent: Shift[]; cover_requests: Delegation[]; my_requests: Delegation[]; handovers: Handover[] };
type Person = { id: number; name: string; role: string | null };

const when = (s: Shift) => `${formatDate(s.starts_at, { weekday: "short", day: "2-digit", month: "short" })} · ${hhmm(s.starts_at)}–${hhmm(s.ends_at)}`;

/** A staff member's own shifts: start, end with a handover, ask a colleague to cover, answer requests. */
export default function MyShiftsPage() {
  const t = useT();
  const q = useApi<ApiResponse<Mine>>("admin/shifts/mine");
  const people = useApi<ApiResponse<Person[]>>("admin/shifts/staff");
  const [ending, setEnding] = useState<Shift | null>(null);
  const [delegating, setDelegating] = useState<Shift | null>(null);
  const [handover, setHandover] = useState<Handover | null>(null);
  const [answer, setAnswer] = useState<{ d: Delegation; accept: boolean } | null>(null);
  const refresh = [["admin/shifts/mine"], ["admin/shifts"]];
  const start = useAction((id: number) => api.post(`admin/shifts/${id}/start`), { invalidate: refresh });
  const cancelRequest = useAction((id: number) => api.post(`admin/shifts/delegations/${id}/cancel`), { invalidate: refresh });

  if (q.isLoading) return <Spinner variant="page" />;
  const d = q.data?.data;
  if (!d) return null;
  const cur = d.current;
  const pendingRequest = (s: Shift) => s.delegations?.find((x) => x.status === "pending");

  return (
    <Guard perm="shifts.own">
      <PageHeader title={t("My shifts")} description={t("Start your shift when you arrive and hand over when you leave. In an emergency, ask a colleague to cover for you.")} />

      {(d.handovers.length > 0 || d.cover_requests.length > 0) && (
        <div className="mb-5 space-y-3">
          {d.handovers.map((h) => (
            <div key={h.id} className="rounded-lg bg-saffron-50 p-4 ring-1 ring-saffron-200">
              <p className="font-semibold text-saffron-900">{t("{name} handed over to you", { name: h.from_user?.name ?? "" })}</p>
              {h.notes && <p className="mt-1 whitespace-pre-line text-sm text-ink-800">{h.notes}</p>}
              {h.cash_counted !== null && <p className="mt-1 text-sm text-ink-700">{t("Cash counted: {amount}", { amount: money(h.cash_counted) })}</p>}
              <Button size="sm" className="mt-3" icon={<Check className="size-4" />} onClick={() => setHandover(h)}>{t("Confirm handover")}</Button>
            </div>
          ))}
          {d.cover_requests.map((r) => (
            <div key={r.id} className={cn("rounded-lg p-4 ring-1", r.emergency ? "bg-ember-50 ring-ember-200" : "bg-sky-50 ring-sky-200")}>
              <p className="font-semibold text-ink-900">{r.emergency && <Badge tone="red" className="mr-2">{t("Emergency")}</Badge>}{t("{name} asks you to cover a shift", { name: r.from_user?.name ?? "" })}</p>
              {r.shift && <p className="text-sm text-ink-700">{when(r.shift)}{r.shift.branch ? ` · ${r.shift.branch.name}` : ""}</p>}
              <p className="mt-1 text-sm text-ink-700">“{r.reason}”</p>
              <div className="mt-3 flex gap-2">
                <Button size="sm" icon={<Check className="size-4" />} onClick={() => setAnswer({ d: r, accept: true })}>{t("I will cover it")}</Button>
                <Button size="sm" variant="secondary" icon={<X className="size-4" />} onClick={() => setAnswer({ d: r, accept: false })}>{t("I can't")}</Button>
              </div>
            </div>
          ))}
        </div>
      )}

      <Panel title={cur?.status === "in_progress" ? t("Working now") : t("Next shift")} className="mb-5">
        {!cur ? <EmptyState icon={<CalendarClock className="size-9" />} title={t("No shift planned for you")} /> : (
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div>
              <p className="text-xl font-semibold text-ink-950">{when(cur)}</p>
              <p className="text-sm text-ink-600">{[cur.type?.name, cur.branch?.name].filter(Boolean).join(" · ")}</p>
              {cur.started_at && <p className="text-sm text-brand-700">{t("Started at {time}", { time: hhmm(cur.started_at) })}</p>}
              {cur.received_handover && <p className="mt-1 text-sm text-ink-600">{t("Handed over by {name}", { name: cur.received_handover.from_user?.name ?? "" })}{cur.received_handover.notes ? `: ${cur.received_handover.notes}` : ""}</p>}
              {cur.status === "in_progress" && d.next_shift && <p className="mt-1 text-sm text-ink-600">{t("Next on duty: {name} at {time}", { name: d.next_shift.user?.name ?? "", time: hhmm(d.next_shift.starts_at) })}</p>}
              {pendingRequest(cur) && <p className="mt-1 text-sm text-saffron-700">{t("Waiting for {name} to answer your request.", { name: pendingRequest(cur)!.to_user?.name ?? "" })}</p>}
            </div>
            <div className="flex flex-wrap gap-2">
              {cur.status === "scheduled" && <Button size="lg" icon={<LogIn className="size-5" />} loading={start.isPending} onClick={() => start.mutate(cur.id)}>{t("Start my shift")}</Button>}
              {cur.status === "in_progress" && <Button size="lg" icon={<LogOut className="size-5" />} onClick={() => setEnding(cur)}>{t("End shift & hand over")}</Button>}
              {!pendingRequest(cur) && <Button size="lg" variant="secondary" icon={<HandHelping className="size-5" />} onClick={() => setDelegating(cur)}>{t("Ask someone to cover")}</Button>}
            </div>
          </div>
        )}
      </Panel>

      <div className="grid gap-5 lg:grid-cols-2">
        <Panel title={t("Coming up")} padded={false}>
          {!d.upcoming.length ? <p className="p-4 text-sm text-ink-500">{t("Nothing else planned.")}</p> : (
            <ul className="divide-y divide-ink-100">
              {d.upcoming.map((s) => (
                <li key={s.id} className="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                  <span><span className="font-medium text-ink-900">{when(s)}</span><span className="block text-xs text-ink-500">{[s.type?.name, s.branch?.name].filter(Boolean).join(" · ")}</span></span>
                  {pendingRequest(s) ? <Badge tone="saffron">{t("Asked {name}", { name: pendingRequest(s)!.to_user?.name ?? "" })}</Badge>
                    : <Button size="sm" variant="ghost" icon={<ArrowRightLeft className="size-4" />} onClick={() => setDelegating(s)}>{t("Ask someone to cover")}</Button>}
                </li>
              ))}
            </ul>
          )}
        </Panel>
        <Panel title={t("My requests and past shifts")} padded={false}>
          <ul className="divide-y divide-ink-100 text-sm">
            {d.my_requests.map((r) => (
              <li key={`r${r.id}`} className="flex items-center justify-between gap-3 px-4 py-2.5">
                <span>{t("Asked {name} to cover", { name: r.to_user?.name ?? "" })}<span className="block text-xs text-ink-500">{r.shift ? when(r.shift) : ""}{r.response_note ? ` · “${r.response_note}”` : ""}</span></span>
                {r.status === "pending" ? <Button size="sm" variant="ghost" onClick={() => cancelRequest.mutate(r.id)}>{t("Cancel request")}</Button>
                  : <Badge tone={r.status === "accepted" ? "green" : "red"}>{t(r.status === "accepted" ? "Accepted" : r.status === "declined" ? "Declined" : "Cancelled")}</Badge>}
              </li>
            ))}
            {d.recent.map((s) => (
              <li key={s.id} className="flex items-center justify-between gap-3 px-4 py-2.5">
                <span>{when(s)}{s.handover && <span className="block text-xs text-ink-500">{t("Handed over to {name}", { name: s.handover.to_user?.name ?? "" })}{s.handover.status === "accepted" ? ` ✓` : ""}</span>}</span>
                <Badge tone={SHIFT_STATUS_TONES[s.status]}>{t(SHIFT_STATUS_LABELS[s.status])}</Badge>
              </li>
            ))}
            {!d.my_requests.length && !d.recent.length && <li className="p-4 text-ink-500">{t("Nothing yet.")}</li>}
          </ul>
        </Panel>
      </div>

      {ending && <EndDialog shift={ending} next={d.next_shift} people={people.data?.data ?? []} onClose={() => setEnding(null)} />}
      {delegating && <DelegateDialog shift={delegating} people={people.data?.data ?? []} onClose={() => setDelegating(null)} />}
      {handover && <AcceptHandoverDialog handover={handover} onClose={() => setHandover(null)} />}
      {answer && <AnswerDialog delegation={answer.d} accept={answer.accept} onClose={() => setAnswer(null)} />}
    </Guard>
  );
}

function EndDialog({ shift, next, people, onClose }: { shift: Shift; next: Shift | null; people: Person[]; onClose: () => void }) {
  const t = useT();
  const [to, setTo] = useState(next ? String(next.user_id) : "");
  const [notes, setNotes] = useState("");
  const [cash, setCash] = useState("");
  const end = useAction(() => api.post(`admin/shifts/${shift.id}/end`, { to_user_id: to ? Number(to) : null, notes: notes || null, cash_counted: cash === "" ? null : Number(cash) }), {
    invalidate: [["admin/shifts/mine"], ["admin/shifts"]], onSuccess: onClose,
  });
  const e = fieldErrors(end.error);
  return (
    <Modal open onClose={onClose} title={t("End shift and hand over")} description={next ? t("{name} works next, from {time}.", { name: next.user?.name ?? "", time: hhmm(next.starts_at) }) : undefined}
      footer={<><Button variant="ghost" onClick={onClose}>{t("Cancel")}</Button><Button icon={<LogOut className="size-4" />} loading={end.isPending} onClick={() => end.mutate(undefined)}>{t("End shift")}</Button></>}>
      <div className="space-y-3">
        <Field label={t("Hand over to")} error={e.to_user_id}>{(p) => (
          <Select {...p} value={to} onChange={(ev) => setTo(ev.target.value)}>
            <option value="">{t("Nobody (no one works after me)")}</option>
            {people.filter((x) => x.id !== shift.user_id).map((x) => <option key={x.id} value={x.id}>{x.name}{x.role ? ` (${x.role})` : ""}</option>)}
          </Select>
        )}</Field>
        <Field label={t("What should they know?")} hint={t("Problems, things still to do, customers waiting...")} error={e.notes}>{(p) => <Textarea {...p} rows={4} value={notes} onChange={(ev) => setNotes(ev.target.value)} />}</Field>
        <Field label={t("Cash counted (optional)")} error={e.cash_counted}>{(p) => <Input {...p} type="number" min={0} step="any" value={cash} onChange={(ev) => setCash(ev.target.value)} />}</Field>
      </div>
    </Modal>
  );
}

function DelegateDialog({ shift, people, onClose }: { shift: Shift; people: Person[]; onClose: () => void }) {
  const t = useT();
  const [to, setTo] = useState("");
  const [reason, setReason] = useState("");
  const [emergency, setEmergency] = useState(false);
  const ask = useAction(() => api.post(`admin/shifts/${shift.id}/delegate`, { to_user_id: Number(to), reason, emergency }), { invalidate: [["admin/shifts/mine"], ["admin/shifts"]], onSuccess: onClose });
  const e = fieldErrors(ask.error);
  return (
    <Modal open onClose={onClose} title={t("Ask a colleague to cover")} description={when(shift)}
      footer={<><Button variant="ghost" onClick={onClose}>{t("Cancel")}</Button><Button icon={<HandHelping className="size-4" />} loading={ask.isPending} disabled={!to || reason.trim().length < 3} onClick={() => ask.mutate(undefined)}>{t("Send request")}</Button></>}>
      <div className="space-y-3">
        <Field label={t("Colleague")} required error={e.to_user_id}>{(p) => (
          <Select {...p} value={to} onChange={(ev) => setTo(ev.target.value)}>
            <option value="">{t("Choose")}</option>
            {people.filter((x) => x.id !== shift.user_id).map((x) => <option key={x.id} value={x.id}>{x.name}{x.role ? ` (${x.role})` : ""}</option>)}
          </Select>
        )}</Field>
        <Field label={t("Reason")} required error={e.reason}>{(p) => <Textarea {...p} rows={3} value={reason} onChange={(ev) => setReason(ev.target.value)} placeholder={t("e.g. my child is sick")} />}</Field>
        <Checkbox label={t("This is an emergency (managers are told straight away)")} checked={emergency} onChange={(ev) => setEmergency(ev.target.checked)} />
        <p className="text-xs text-ink-500">{t("The shift stays yours until your colleague accepts.")}</p>
      </div>
    </Modal>
  );
}

function AcceptHandoverDialog({ handover, onClose }: { handover: Handover; onClose: () => void }) {
  const t = useT();
  const [comment, setComment] = useState("");
  const [cash, setCash] = useState(handover.cash_counted ?? "");
  const accept = useAction(() => api.post(`admin/shifts/handovers/${handover.id}/accept`, { comment: comment || null, cash_confirmed: cash === "" ? null : Number(cash) }), {
    invalidate: [["admin/shifts/mine"]], onSuccess: onClose,
  });
  return (
    <Modal open onClose={onClose} title={t("Confirm handover from {name}", { name: handover.from_user?.name ?? "" })} description={dateTime(handover.created_at)}
      footer={<><Button variant="ghost" onClick={onClose}>{t("Cancel")}</Button><Button icon={<Check className="size-4" />} loading={accept.isPending} onClick={() => accept.mutate(undefined)}>{t("Confirm")}</Button></>}>
      <div className="space-y-3">
        {handover.notes && <p className="whitespace-pre-line rounded-md bg-ink-50 p-3 text-sm text-ink-800">{handover.notes}</p>}
        {handover.cash_counted !== null && (
          <Field label={t("Cash you found")} hint={t("They counted {amount}. A different figure is reported to the managers.", { amount: money(handover.cash_counted) })}>
            {(p) => <Input {...p} type="number" min={0} step="any" value={cash} onChange={(ev) => setCash(ev.target.value)} />}
          </Field>
        )}
        <Field label={t("Comment (optional)")}>{(p) => <Textarea {...p} rows={3} value={comment} onChange={(ev) => setComment(ev.target.value)} />}</Field>
      </div>
    </Modal>
  );
}

function AnswerDialog({ delegation, accept, onClose }: { delegation: Delegation; accept: boolean; onClose: () => void }) {
  const t = useT();
  const [note, setNote] = useState("");
  const respond = useAction(() => api.post(`admin/shifts/delegations/${delegation.id}/respond`, { accept, note: note || null }), { invalidate: [["admin/shifts/mine"], ["admin/shifts"]], onSuccess: onClose });
  return (
    <Modal open onClose={onClose} size="sm" title={accept ? t("Cover this shift?") : t("Say you can't cover it?")} description={delegation.shift ? `${when(delegation.shift)} · ${date(delegation.shift.starts_at)}` : undefined}
      footer={<><Button variant="ghost" onClick={onClose}>{t("Back")}</Button><Button variant={accept ? "primary" : "danger"} loading={respond.isPending} onClick={() => respond.mutate(undefined)}>{accept ? t("Yes, it's mine") : t("I can't")}</Button></>}>
      <Field label={t("Message to {name} (optional)", { name: delegation.from_user?.name ?? "" })}>{(p) => <Textarea {...p} rows={3} value={note} onChange={(ev) => setNote(ev.target.value)} />}</Field>
    </Modal>
  );
}
