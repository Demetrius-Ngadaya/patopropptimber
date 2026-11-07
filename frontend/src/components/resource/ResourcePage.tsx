"use client";

import { useState } from "react";
import { Pencil, Plus, RotateCcw, Trash2 } from "lucide-react";
import { api, type ApiResponse, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { fieldErrors, useAction, useApi, useDebounced } from "@/lib/hooks";
import { Badge, Button, Checkbox, ConfirmDialog, DataTable, Field, Input, Modal, PageHeader, Pagination, Panel, SearchInput, Select, Switch, Textarea, type Column } from "@/components/ui";
import { useT } from "@/lib/i18n";

export type ResourceField = {
  name: string;
  label: string;
  type?: "text" | "number" | "email" | "date" | "textarea" | "select" | "switch";
  required?: boolean;
  hint?: string;
  options?: Array<{ value: string | number; label: string }>;
  placeholder?: string;
  span?: 1 | 2;
};

type Row = { id: number; deleted_at?: string | null; [key: string]: unknown };

type Props = {
  title: string;
  description?: string;
  endpoint: string;
  /** Permission prefix, e.g. "categories" -> categories.create / update / delete / restore. */
  perm: string;
  /** Override individual permissions when they don't follow the prefix pattern. */
  perms?: Partial<Record<"create" | "update" | "delete" | "restore", string>>;
  noun: string;
  columns: Column<Row>[];
  fields: ResourceField[];
  defaults: Record<string, unknown>;
  toForm?: (row: Row) => Record<string, unknown>;
};

/** List + search + create / edit modal + soft delete + restore for simple records. */
export function ResourcePage({ title, description, endpoint, perm, perms, noun, columns, fields, defaults, toForm }: Props) {
  const t = useT();
  const { can } = useAuth();
  const p = (action: "create" | "update" | "delete" | "restore") => perms?.[action] ?? `${perm}.${action}`;
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [trashed, setTrashed] = useState(false);
  const [editing, setEditing] = useState<Row | null>(null);
  const [creating, setCreating] = useState(false);
  const [form, setForm] = useState<Record<string, unknown>>(defaults);
  const [deleting, setDeleting] = useState<Row | null>(null);
  const q = useDebounced(search);

  const list = useApi<Paginated<Row>>(endpoint, { search: q, page, trashed: trashed ? "only" : undefined, per_page: 25 });

  const close = () => {
    setCreating(false);
    setEditing(null);
  };
  const save = useAction(
    (body: Record<string, unknown>) => (editing ? api.put<ApiResponse<Row>>(`${endpoint}/${editing.id}`, body) : api.post<ApiResponse<Row>>(endpoint, body)),
    { invalidate: [[endpoint]], onSuccess: close },
  );
  const remove = useAction((row: Row) => api.del(`${endpoint}/${row.id}`), { invalidate: [[endpoint]], onSuccess: () => setDeleting(null) });
  const restore = useAction((row: Row) => api.post(`${endpoint}/${row.id}/restore`), { invalidate: [[endpoint]] });
  const errors = fieldErrors(save.error);

  const openCreate = () => {
    save.reset();
    setForm(defaults);
    setCreating(true);
  };
  const openEdit = (row: Row) => {
    save.reset();
    setForm(toForm ? toForm(row) : { ...defaults, ...Object.fromEntries(fields.map((f) => [f.name, row[f.name] ?? defaults[f.name]])) });
    setEditing(row);
  };

  const actionColumn: Column<Row> = {
    key: "_actions",
    header: <span className="sr-only">{t("Actions")}</span>,
    align: "right",
    render: (row) =>
      row.deleted_at ? (
        can(p("restore")) && (
          <Button size="sm" variant="secondary" icon={<RotateCcw className="size-3.5" />} loading={restore.isPending && restore.variables?.id === row.id} onClick={() => restore.mutate(row)}>
            {t("Restore")}
          </Button>
        )
      ) : (
        <div className="flex justify-end gap-1">
          {can(p("update")) && (
            <Button size="sm" variant="ghost" onClick={() => openEdit(row)} aria-label={t("Edit {value}", { value: String(row.name ?? "") })}>
              <Pencil className="size-4" />
            </Button>
          )}
          {can(p("delete")) && (
            <Button size="sm" variant="ghost" className="text-ember-600 hover:bg-ember-50" onClick={() => setDeleting(row)} aria-label={t("Delete {value}", { value: String(row.name ?? "") })}>
              <Trash2 className="size-4" />
            </Button>
          )}
        </div>
      ),
  };

  return (
    <>
      <PageHeader
        title={title}
        description={description}
        actions={can(p("create")) && <Button icon={<Plus className="size-4" />} onClick={openCreate}>{t("Add {noun}", { noun: noun })}</Button>}
      />
      <Panel padded={false}>
        <div className="flex flex-wrap items-center gap-3 border-b border-ink-100 p-3">
          <SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t("Search {toLowerCase}", { toLowerCase: title.toLowerCase() })} className="w-full max-w-xs" />
          {can(p("restore")) && (
            <Checkbox label={t("Show deleted")} checked={trashed} onChange={(e) => { setTrashed(e.target.checked); setPage(1); }} />
          )}
          {trashed && <Badge tone="red">{t("Showing deleted records")}</Badge>}
        </div>
        <DataTable
          columns={[...columns, actionColumn]}
          rows={list.data?.data}
          loading={list.isFetching}
          rowKey={(r) => r.id}
          empty={trashed ? { title: t("No deleted records") } : { title: t("No {toLowerCase} yet", { toLowerCase: title.toLowerCase() }), action: can(p("create")) ? <Button onClick={openCreate}>{t("Add the first {noun}", { noun: noun })}</Button> : undefined }}
        />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>

      <Modal
        open={creating || Boolean(editing)}
        onClose={close}
        title={editing ? t("Edit {noun}", { noun: noun }) : t("Add {noun}", { noun: noun })}
        footer={
          <>
            <Button variant="secondary" onClick={close}>{t("Cancel")}</Button>
            <Button type="submit" form="resource-form" loading={save.isPending}>{editing ? t("Save changes") : t("Add {noun}", { noun: noun })}</Button>
          </>
        }
      >
        <form
          id="resource-form"
          className="grid gap-4 sm:grid-cols-2"
          onSubmit={(e) => {
            e.preventDefault();
            save.mutate(form);
          }}
        >
          {fields.map((f) => {
            const value = form[f.name];
            const set = (v: unknown) => setForm((s) => ({ ...s, [f.name]: v }));
            if (f.type === "switch") {
              return (
                <div key={f.name} className="sm:col-span-2">
                  <Switch label={f.label} description={f.hint} checked={Boolean(value)} onChange={set} />
                </div>
              );
            }
            return (
              <Field key={f.name} label={f.label} required={f.required} hint={f.hint} error={errors[f.name]} className={f.span === 2 || f.type === "textarea" ? "sm:col-span-2" : undefined}>
                {(props) =>
                  f.type === "textarea" ? (
                    <Textarea {...props} value={String(value ?? "")} onChange={(e) => set(e.target.value)} placeholder={f.placeholder} />
                  ) : f.type === "select" ? (
                    <Select {...props} value={value === null || value === undefined ? "" : String(value)} onChange={(e) => set(e.target.value === "" ? null : e.target.value)}>
                      <option value="">{t("— None —")}</option>
                      {f.options?.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </Select>
                  ) : (
                    <Input {...props} type={f.type ?? "text"} value={String(value ?? "")} onChange={(e) => set(f.type === "number" ? (e.target.value === "" ? null : Number(e.target.value)) : e.target.value)} placeholder={f.placeholder} />
                  )
                }
              </Field>
            );
          })}
        </form>
      </Modal>

      <ConfirmDialog
        open={Boolean(deleting)}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && remove.mutate(deleting)}
        loading={remove.isPending}
        title={t("Delete {noun}?", { noun: noun })}
        message={<>{t("“{value}” will be moved to deleted records. You can restore it later.", { value: String(deleting?.name ?? deleting?.title ?? "") })}</>}
        confirmLabel={t("Delete {noun}", { noun: noun })}
      />
    </>
  );
}
