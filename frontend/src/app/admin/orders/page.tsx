"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { Minus, Plus, Trash2 } from "lucide-react";
import { api, type ApiResponse, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { dateTime, money, toNumber } from "@/lib/format";
import { useAction, useApi, useDebounced, useUrlParam } from "@/lib/hooks";
import type { Customer, Product } from "@/lib/types";
import { Guard } from "@/components/layout/Guard";
import { CustomerPicker } from "@/features/crm/components/CustomerPicker";
import { ProductPicker } from "@/features/catalog/components/ProductPicker";
import { Badge, Button, DataTable, Field, Input, Modal, PageHeader, Pagination, Panel, SearchInput, Select, StatusBadge, Tabs, Textarea } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Order = { id: number; number: string; status: string; source: string; total: string; created_at: string; delivery_method: string; contact_phone: string; items_count: number; customer?: { name: string; phone: string | null }; branch?: { name: string } };

const statusTabs = [
  { value: "open", label: "Open" },
  { value: "pending", label: "New" },
  { value: "confirmed", label: "Confirmed" },
  { value: "ready", label: "Ready" },
  { value: "out_for_delivery", label: "On the way" },
  { value: "completed", label: "Completed" },
  { value: "cancelled", label: "Cancelled" },
  { value: "", label: "All" },
];

function NewOrder({ open, onClose }: { open: boolean; onClose: () => void }) {
  const tr = useT();
  const router = useRouter();
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [items, setItems] = useState<Array<{ product: Product; quantity: number }>>([]);
  const [form, setForm] = useState({ delivery_method: "pickup", delivery_address: "", contact_phone: "", preferred_date: "", staff_notes: "" });
  const save = useAction(
    () => api.post<ApiResponse<{ id: number }>>("admin/orders", {
      customer_id: customer?.id,
      items: items.map((i) => ({ product_id: i.product.id, quantity: i.quantity })),
      ...form,
      delivery_address: form.delivery_address || undefined,
      contact_phone: form.contact_phone || undefined,
      preferred_date: form.preferred_date || undefined,
    }),
    { invalidate: [["admin/orders"]], onSuccess: (r) => router.push(`/admin/orders/${r.data.id}`) },
  );
  const total = items.reduce((s, i) => s + i.quantity * toNumber(i.product.selling_price), 0);
  const add = (p: Product) => setItems((xs) => (xs.some((x) => x.product.id === p.id) ? xs.map((x) => (x.product.id === p.id ? { ...x, quantity: x.quantity + 1 } : x)) : [...xs, { product: p, quantity: 1 }]));

  return (
    <Modal open={open} onClose={onClose} title={tr("New customer order")} description={tr("For orders taken by phone or WhatsApp. Stock is taken when the order is completed.")} size="lg"
      footer={<><Button variant="secondary" onClick={onClose}>{tr("Cancel")}</Button><Button loading={save.isPending} disabled={!customer || !items.length} onClick={() => save.mutate(undefined)}>{tr("Create order")} <span className="num">{money(total)}</span></Button></>}>
      <div className="space-y-4">
        <Field label={tr("Customer")} required>{() => <CustomerPicker value={customer} onChange={setCustomer} />}</Field>
        <ProductPicker onSelect={add} />
        {items.length > 0 && (
          <ul className="divide-y divide-ink-100 rounded-lg ring-1 ring-ink-200">
            {items.map((i) => (
              <li key={i.product.id} className="flex items-center justify-between gap-3 px-3 py-2">
                <span className="min-w-0 truncate text-sm">{i.product.name}</span>
                <div className="flex items-center gap-2">
                  <Button size="sm" variant="ghost" aria-label={tr("Less")} onClick={() => setItems((xs) => xs.map((x) => (x.product.id === i.product.id ? { ...x, quantity: Math.max(1, x.quantity - 1) } : x)))}><Minus className="size-3.5" /></Button>
                  <Input aria-label={tr("Quantity")} type="number" step="any" min={0} className="num h-8 w-20 text-center" value={i.quantity} onChange={(e) => setItems((xs) => xs.map((x) => (x.product.id === i.product.id ? { ...x, quantity: Number(e.target.value) } : x)))} />
                  <Button size="sm" variant="ghost" aria-label={tr("More")} onClick={() => setItems((xs) => xs.map((x) => (x.product.id === i.product.id ? { ...x, quantity: x.quantity + 1 } : x)))}><Plus className="size-3.5" /></Button>
                  <span className="num w-24 text-right text-sm font-medium">{money(i.quantity * toNumber(i.product.selling_price))}</span>
                  <Button size="sm" variant="ghost" aria-label={tr("Remove")} onClick={() => setItems((xs) => xs.filter((x) => x.product.id !== i.product.id))}><Trash2 className="size-4 text-ember-600" /></Button>
                </div>
              </li>
            ))}
          </ul>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={tr("Delivery")}>{(p) => <Select {...p} value={form.delivery_method} onChange={(e) => setForm({ ...form, delivery_method: e.target.value })}><option value="pickup">{tr("Customer picks up")}</option><option value="delivery">{tr("We deliver")}</option></Select>}</Field>
          <Field label={tr("Wanted on")}>{(p) => <Input {...p} type="date" value={form.preferred_date} onChange={(e) => setForm({ ...form, preferred_date: e.target.value })} />}</Field>
          {form.delivery_method === "delivery" && <Field label={tr("Delivery address")} required className="sm:col-span-2">{(p) => <Input {...p} value={form.delivery_address} onChange={(e) => setForm({ ...form, delivery_address: e.target.value })} />}</Field>}
          <Field label={tr("Contact phone")} hint={tr("Defaults to the customer's phone.")}>{(p) => <Input {...p} value={form.contact_phone} onChange={(e) => setForm({ ...form, contact_phone: e.target.value })} />}</Field>
          <Field label={tr("Notes")}>{(p) => <Textarea {...p} rows={1} value={form.staff_notes} onChange={(e) => setForm({ ...form, staff_notes: e.target.value })} />}</Field>
        </div>
      </div>
    </Modal>
  );
}

export default function OrdersPage() {
  const tr = useT();
  const router = useRouter();
  const { can } = useAuth();
  const [status, setStatus] = useState("open");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  useUrlParam("status", setStatus);
  const q = useDebounced(search);
  const list = useApi<Paginated<Order> & { counts: Record<string, number> }>("admin/orders", { status, search: q, page });
  const counts = list.data?.counts ?? {};

  return (
    <Guard perm="orders.view">
      <PageHeader title={tr("Orders")} description={tr("Orders from the online shop and orders taken for customers. Payment is collected on pickup or delivery.")} actions={can("orders.create") && <Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>{tr("New order")}</Button>} />
      <Panel padded={false}>
        <div className="px-3 pt-2">
          <Tabs value={status} onChange={(v) => { setStatus(v); setPage(1); }} tabs={statusTabs.map((t) => ({ ...t, label: tr(t.label), count: t.value && t.value !== "open" ? counts[t.value] ?? 0 : undefined }))} />
        </div>
        <div className="border-b border-ink-100 p-3"><SearchInput value={search} onChange={setSearch} placeholder={tr("Order number, customer or phone")} className="max-w-sm" /></div>
        <DataTable rows={list.data?.data} loading={list.isFetching} rowKey={(r) => r.id} onRowClick={(r) => router.push(`/admin/orders/${r.id}`)}
          columns={[
            { key: "number", header: tr("Order"), render: (r) => <span className="font-medium">{r.number}{r.source === "online" && <Badge tone="blue" className="ml-2">{tr("Online")}</Badge>}</span> },
            { key: "created_at", header: tr("Placed"), render: (r) => dateTime(r.created_at) },
            { key: "customer.name", header: tr("Customer"), render: (r) => <>{r.customer?.name}<span className="block text-xs text-ink-500">{r.contact_phone}</span></> },
            { key: "delivery_method", header: tr("Fulfilment"), hideOnMobile: true, render: (r) => (r.delivery_method === "delivery" ? "Delivery" : "Pickup") },
            { key: "items_count", header: tr("Items"), align: "right", hideOnMobile: true },
            { key: "total", header: tr("Total"), align: "right", render: (r) => money(r.total) },
            { key: "status", header: tr("Status"), render: (r) => <StatusBadge status={r.status} /> },
          ]}
          empty={{ title: tr("No orders here"), message: tr("New online orders appear in the New tab.") }}
        />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
      {creating && <NewOrder open={creating} onClose={() => setCreating(false)} />}
    </Guard>
  );
}
