"use client";

import { useState } from "react";
import { Eye, Megaphone, Plus, Trash2 } from "lucide-react";
import { api, type ApiResponse, type Paginated } from "@/lib/api";
import { cn, dateTime } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { useLookups } from "@/lib/lookups";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, Checkbox, ConfirmDialog, DataTable, Field, Input, Modal, PageHeader, Pagination, Panel, Select, Spinner, Switch, Textarea } from "@/components/ui";
import { ChannelPicker } from "@/features/messaging/ChannelPicker";
import type { Channel } from "@/features/messaging/channels";
import { KindBadge } from "@/features/messages/PendingMessages";
import type { MessageKind, StaffMessage } from "@/features/messages/types";

type Role = { id: number; name: string; is_developer?: boolean; deleted_at?: string | null };
type Person = { id: number; name: string; role: string | null };
type Detail = StaffMessage & { recipients: Array<{ id: number; read_at: string | null; acknowledged_at: string | null; user?: { id: number; name: string; role?: { name: string } | null } }> };

/** Admin: write to staff and see who has read it. */
export default function AnnouncementsPage() {
  const t = useT();
  const [page, setPage] = useState(1);
  const [composing, setComposing] = useState(false);
  const [viewing, setViewing] = useState<number | null>(null);
  const [withdrawing, setWithdrawing] = useState<StaffMessage | null>(null);
  const list = useApi<Paginated<StaffMessage>>("admin/staff-messages/sent", { page });
  const withdraw = useAction((id: number) => api.del(`admin/staff-messages/${id}`), { invalidate: [["admin/staff-messages/sent"]], onSuccess: () => setWithdrawing(null) });

  return (
    <Guard perm="messages.send">
      <PageHeader
        title={t("Announcements to staff")}
        description={t("Write to everyone, to some roles or to chosen people. Reminders and announcements pop up when they log in until they confirm reading.")}
        actions={<Button icon={<Plus className="size-4" />} onClick={() => setComposing(true)}>{t("New message")}</Button>}
      />
      <Panel padded={false}>
        <DataTable
          rows={list.data?.data}
          loading={list.isFetching}
          rowKey={(r) => r.id}
          onRowClick={(r) => setViewing(r.id)}
          columns={[
            { key: "title", header: t("Message"), render: (r) => <span className="font-medium text-ink-900">{r.title}<span className="block max-w-md truncate text-xs font-normal text-ink-500">{r.body}</span></span> },
            { key: "kind", header: t("Kind"), render: (r) => <span className="flex gap-1"><KindBadge kind={r.kind} />{r.priority === "high" && <Badge tone="red">{t("Important")}</Badge>}</span> },
            { key: "read", header: t("Read"), align: "right", render: (r) => `${r.read_count ?? 0} / ${r.recipients_count ?? 0}` },
            { key: "ack", header: t("Confirmed"), align: "right", hideOnMobile: true, render: (r) => r.requires_ack ? `${r.acknowledged_count ?? 0} / ${r.recipients_count ?? 0}` : "—" },
            { key: "created_at", header: t("Sent"), hideOnMobile: true, render: (r) => <span>{dateTime(r.created_at)}<span className="block text-xs text-ink-500">{r.sender?.name}</span></span> },
            { key: "_x", header: "", align: "right", render: (r) => <Button size="sm" variant="ghost" aria-label={t("Withdraw")} onClick={(e) => { e.stopPropagation(); setWithdrawing(r); }}><Trash2 className="size-4" /></Button> },
          ]}
          empty={{ title: t("Nothing sent yet"), action: <Button icon={<Megaphone className="size-4" />} onClick={() => setComposing(true)}>{t("Write the first message")}</Button> }}
        />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
      {composing && <Compose onClose={() => setComposing(false)} />}
      {viewing && <Readers id={viewing} onClose={() => setViewing(null)} />}
      <ConfirmDialog open={Boolean(withdrawing)} onClose={() => setWithdrawing(null)} title={t("Withdraw this message?")} message={t("Staff no longer see it.")} confirmLabel={t("Withdraw")}
        loading={withdraw.isPending} onConfirm={() => withdrawing && withdraw.mutate(withdrawing.id)} />
    </Guard>
  );
}

function Compose({ onClose }: { onClose: () => void }) {
  const t = useT();
  const roles = useApi<ApiResponse<Role[]>>("admin/roles");
  const people = useApi<ApiResponse<Person[]>>("admin/notes/people");
  const { branches = [] } = useLookups(["branches"]);
  const [f, setF] = useState({ kind: "announcement" as MessageKind, priority: "normal", title: "", body: "", audience: "all", branch_id: "", requires_ack: true, expires_at: "" });
  const [roleIds, setRoleIds] = useState<number[]>([]);
  const [userIds, setUserIds] = useState<number[]>([]);
  const [channels, setChannels] = useState<Channel[]>([]);
  const send = useAction(() => api.post("admin/staff-messages", {
    ...f, branch_id: f.branch_id ? Number(f.branch_id) : null, expires_at: f.expires_at || null,
    role_ids: f.audience === "roles" ? roleIds : null, user_ids: f.audience === "users" ? userIds : null, channels,
  }), { invalidate: [["admin/staff-messages/sent"]], onSuccess: onClose });
  const e = fieldErrors(send.error);
  const toggle = (list: number[], set: (v: number[]) => void, id: number) => set(list.includes(id) ? list.filter((x) => x !== id) : [...list, id]);

  return (
    <Modal open onClose={onClose} size="lg" title={t("New message to staff")}
      footer={<><Button variant="ghost" onClick={onClose}>{t("Cancel")}</Button><Button icon={<Megaphone className="size-4" />} loading={send.isPending} onClick={() => send.mutate(undefined)}>{t("Send")}</Button></>}>
      <div className="space-y-4">
        <div className="grid gap-2 sm:grid-cols-3">
          {(["announcement", "reminder", "message"] as const).map((k) => (
            <button key={k} type="button" aria-pressed={f.kind === k} onClick={() => setF({ ...f, kind: k, requires_ack: k !== "message" })}
              className={cn("rounded-md px-3 py-2 text-left text-sm ring-1", f.kind === k ? "bg-brand-50 ring-brand-500" : "ring-ink-200 hover:bg-ink-50")}>
              <span className="block font-medium">{t(k === "announcement" ? "Announcement" : k === "reminder" ? "Reminder" : "Message")}</span>
              <span className="text-xs text-ink-500">{t(k === "message" ? "Goes to the inbox" : "Pops up at login until confirmed")}</span>
            </button>
          ))}
        </div>
        <Field label={t("Title")} required error={e.title}>{(p) => <Input {...p} value={f.title} onChange={(ev) => setF({ ...f, title: ev.target.value })} />}</Field>
        <Field label={t("Message")} required error={e.body}>{(p) => <Textarea {...p} rows={6} value={f.body} onChange={(ev) => setF({ ...f, body: ev.target.value })} />}</Field>
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label={t("Send to")}>{(p) => (
            <Select {...p} value={f.audience} onChange={(ev) => setF({ ...f, audience: ev.target.value })}>
              <option value="all">{t("All staff")}</option><option value="roles">{t("Some roles")}</option><option value="users">{t("Chosen people")}</option>
            </Select>
          )}</Field>
          <Field label={t("Branch")}>{(p) => (
            <Select {...p} value={f.branch_id} onChange={(ev) => setF({ ...f, branch_id: ev.target.value })}>
              <option value="">{t("All branches")}</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </Select>
          )}</Field>
          <Field label={t("Show until (optional)")} error={e.expires_at}>{(p) => <Input {...p} type="datetime-local" value={f.expires_at} onChange={(ev) => setF({ ...f, expires_at: ev.target.value })} />}</Field>
        </div>
        {f.audience === "roles" && (
          <div className="grid gap-1.5 rounded-md p-3 ring-1 ring-ink-200 sm:grid-cols-3">
            {roles.data?.data.filter((r) => !r.is_developer && !r.deleted_at).map((r) => <Checkbox key={r.id} label={r.name} checked={roleIds.includes(r.id)} onChange={() => toggle(roleIds, setRoleIds, r.id)} />)}
            {e.role_ids && <p className="text-xs text-ember-600 sm:col-span-3">{e.role_ids}</p>}
          </div>
        )}
        {f.audience === "users" && (
          <div className="grid max-h-48 gap-1.5 overflow-y-auto rounded-md p-3 ring-1 ring-ink-200 sm:grid-cols-2">
            {people.data?.data.map((u) => <Checkbox key={u.id} label={<span>{u.name} <span className="text-xs text-ink-500">{u.role}</span></span>} checked={userIds.includes(u.id)} onChange={() => toggle(userIds, setUserIds, u.id)} />)}
            {e.user_ids && <p className="text-xs text-ember-600 sm:col-span-2">{e.user_ids}</p>}
          </div>
        )}
        <div className="space-y-3">
          <Switch label={t("Important")} description={t("Shown in red.")} checked={f.priority === "high"} onChange={(v) => setF({ ...f, priority: v ? "high" : "normal" })} />
          <Switch label={t("Ask them to confirm reading")} description={t("It pops up at login until they press “I have read it”.")} checked={f.requires_ack} onChange={(v) => setF({ ...f, requires_ack: v })} />
        </div>
        <ChannelPicker value={channels} onChange={setChannels} label={t("Also send a copy to their phone / email (optional)")} />
      </div>
    </Modal>
  );
}

function Readers({ id, onClose }: { id: number; onClose: () => void }) {
  const t = useT();
  const q = useApi<ApiResponse<Detail>>(`admin/staff-messages/sent/${id}`);
  const m = q.data?.data;
  return (
    <Modal open onClose={onClose} size="lg" title={m?.title ?? t("Message")} description={m ? `${dateTime(m.created_at)} · ${m.sender?.name ?? ""}` : undefined}>
      {!m ? <Spinner /> : (
        <>
          <p className="mb-4 whitespace-pre-line text-sm text-ink-800">{m.body}</p>
          <table className="w-full text-sm">
            <thead className="text-left text-xs text-ink-500"><tr><th className="py-1">{t("Person")}</th><th className="py-1">{t("Read")}</th>{m.requires_ack && <th className="py-1">{t("Confirmed")}</th>}</tr></thead>
            <tbody>
              {m.recipients.map((r) => (
                <tr key={r.id} className="border-t border-ink-100">
                  <td className="py-1.5">{r.user?.name}<span className="ml-1 text-xs text-ink-500">{r.user?.role?.name}</span></td>
                  <td className="py-1.5">{r.read_at ? <span className="inline-flex items-center gap-1 text-brand-700"><Eye className="size-3.5" />{dateTime(r.read_at)}</span> : <span className="text-ink-400">{t("Not yet")}</span>}</td>
                  {m.requires_ack && <td className="py-1.5">{r.acknowledged_at ? dateTime(r.acknowledged_at) : <span className="text-ink-400">{t("Not yet")}</span>}</td>}
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}
    </Modal>
  );
}
