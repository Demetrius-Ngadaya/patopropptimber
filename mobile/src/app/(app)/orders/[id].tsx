import { useState } from 'react';
import { Linking, ScrollView, View } from 'react-native';
import { useLocalSearchParams } from 'expo-router';
import { useCan } from '@/lib/session';
import { useLive } from '@/lib/useLive';
import { dateTime, money, phoneIntl, qty } from '@/lib/format';
import { getOrder } from '@/db/repo';
import { completeOrder, setOrderStatus } from '@/db/commands';
import { syncSoon } from '@/sync/manager';
import { useT } from '@/i18n';
import { Badge, Button, Card, Divider, EmptyState, Group, Input, ListRow, NumberInput, Screen, Segmented, Sheet, SkeletonList, Text, confirm, space, toast, useColors } from '@/ui';
import { ORDER_STATUS, ScreenHeader } from '@/features/common';
import { sendWhatsApp } from '@/features/receipt';
import type { OrderStatus, PaymentMethod } from '@/lib/types';

/** Same transitions as the server (backend/app/Models/Sales/Order.php). */
const NEXT: Record<OrderStatus, OrderStatus[]> = {
  pending: ['confirmed', 'cancelled'],
  confirmed: ['processing', 'ready', 'cancelled'],
  processing: ['ready', 'out_for_delivery', 'cancelled'],
  ready: ['out_for_delivery', 'cancelled'],
  out_for_delivery: ['ready', 'cancelled'],
  completed: [],
  cancelled: [],
};

/** An order: what was ordered, the customer, and the next step (works offline). */
export default function OrderScreen() {
  const t = useT();
  const c = useColors();
  const { id } = useLocalSearchParams<{ id: string }>();
  const canUpdate = useCan('orders.update_status');
  const canCancel = useCan('orders.cancel');
  const canComplete = useCan('orders.complete');
  const order = useLive(() => getOrder(Number(id)), ['orders'], [id]);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [payOpen, setPayOpen] = useState(false);
  const [method, setMethod] = useState<PaymentMethod>('cash');
  const [amount, setAmount] = useState<number | null>(null);
  const [reference, setReference] = useState('');

  const o = order.data;
  if (order.loading) return <Screen padded={false} edges={[]}><ScreenHeader title={t('Order')} /><SkeletonList rows={4} /></Screen>;
  if (!o) {
    return (
      <Screen padded={false} edges={[]}>
        <ScreenHeader title={t('Order')} />
        <EmptyState icon="cloud-download-outline" title={t('This order is not on the phone yet')} text={t('It arrives with the next sync. Pull down on Orders to sync now.')} />
      </Screen>
    );
  }

  const status = o.pending_status ?? o.status;
  const meta = ORDER_STATUS[status];
  const next = NEXT[status].filter((s) => (s === 'cancelled' ? canCancel : canUpdate));
  const due = Math.max(0, o.total - o.paid_online);
  const phone = o.contact_phone ?? o.customer_phone;

  const move = async (to: OrderStatus) => {
    if (to === 'cancelled') return setCancelOpen(true);
    await setOrderStatus(o, to);
    syncSoon();
    toast.success(t('Order {number}: {status}', { number: o.number, status: t(ORDER_STATUS[to].label) }));
  };

  const doCancel = async () => {
    if (reason.trim().length < 3) return toast.warning(t('Write why the order is cancelled.'));
    if (!(await confirm({ title: t('Cancel order {number}?', { number: o.number }), message: o.paid_online > 0 ? t('It was paid online: the refund is handled on the web app.') : undefined, confirm: t('Cancel order'), cancel: t('Keep'), danger: true }))) return;
    await setOrderStatus(o, 'cancelled', reason.trim());
    setCancelOpen(false);
    syncSoon();
  };

  const doComplete = async () => {
    const paid = amount ?? due;
    if (paid > 0 && method !== 'cash' && !reference.trim()) return toast.warning(t('Enter the transaction ID / reference.'));
    await completeOrder(o, paid > 0 ? [{ method, amount: paid, reference: reference || null }] : []);
    setPayOpen(false);
    syncSoon();
    toast.success(t('Order {number} completed.', { number: o.number }));
  };

  const actionLabel: Partial<Record<OrderStatus, string>> = {
    confirmed: t('Confirm'),
    processing: t('Start preparing'),
    ready: t('Mark ready'),
    out_for_delivery: t('Send for delivery'),
  };

  return (
    <Screen padded={false} edges={[]}>
      <ScreenHeader title={o.number} subtitle={dateTime(o.created_at)} />
      <ScrollView contentContainerStyle={{ padding: space.lg, gap: space.lg, paddingBottom: 160 }}>
        <Card style={{ gap: space.md }}>
          <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
            <Badge label={t(meta.label)} tone={meta.tone} icon={meta.icon} />
            {o.pending_status ? <Badge label={t('Waiting to sync')} tone="warning" icon="cloud-upload" /> : null}
          </View>
          <Text variant="display" numeric>
            {money(o.total)}
          </Text>
          <View style={{ flexDirection: 'row', gap: space.sm, flexWrap: 'wrap' }}>
            {o.paid_online > 0 ? <Badge label={t('Paid online {amount}', { amount: money(o.paid_online, false) })} tone="success" icon="card" /> : null}
            <Badge label={o.delivery_method === 'delivery' ? t('Delivery') : t('Pickup')} tone="info" icon={o.delivery_method === 'delivery' ? 'bicycle' : 'storefront'} />
            {o.source ? <Badge label={o.source === 'online' ? t('Online shop') : o.source} tone="neutral" /> : null}
          </View>
        </Card>

        <Group>
          <ListRow icon="person" title={o.customer_name ?? t('Customer')} subtitle={phone} chevron={false} />
          {phone ? (
            <View style={{ flexDirection: 'row', gap: space.sm, padding: space.md }}>
              <Button title={t('Call')} icon="call" variant="secondary" size="sm" style={{ flex: 1 }} onPress={() => Linking.openURL(`tel:${phone}`)} />
              <Button title={t('SMS')} icon="chatbubble" variant="secondary" size="sm" style={{ flex: 1 }} onPress={() => Linking.openURL(`sms:${phone}`)} />
              <Button title="WhatsApp" icon="logo-whatsapp" variant="secondary" size="sm" style={{ flex: 1 }} onPress={() => sendWhatsApp(t('Hello {name}, about your order {number}:', { name: o.customer_name ?? '', number: o.number }), phoneIntl(phone))} />
            </View>
          ) : null}
          {o.delivery_address ? (
            <ListRow icon="location" iconTone="info" title={t('Deliver to')} subtitle={o.delivery_address} onPress={() => Linking.openURL(`https://maps.google.com/?q=${encodeURIComponent(o.delivery_address ?? '')}`)} />
          ) : null}
          {o.customer_notes ? <ListRow icon="chatbox-ellipses" iconTone="warning" title={t('Customer note')} subtitle={o.customer_notes} numberOfLines={4} chevron={false} /> : null}
        </Group>

        <Card style={{ gap: space.sm }}>
          <Text variant="label" tone="muted">
            {(o.items.length === 1 ? t('1 item') : t('{n} items', { n: o.items.length }))}
          </Text>
          {o.items.map((i, idx) => (
            <View key={idx} style={{ flexDirection: 'row', gap: space.sm }}>
              <Text variant="bodyStrong" style={{ width: 44, color: c.primary }} numeric>
                {qty(i.quantity)}×
              </Text>
              <Text style={{ flex: 1 }}>{i.name}</Text>
              <Text variant="bodyStrong" numeric>
                {money(i.line_total, false)}
              </Text>
            </View>
          ))}
          <Divider style={{ marginVertical: space.sm }} />
          {o.delivery_fee > 0 ? <Line label={t('Delivery fee')} value={money(o.delivery_fee, false)} /> : null}
          {o.discount > 0 ? <Line label={t('Discount')} value={`− ${money(o.discount, false)}`} /> : null}
          <Line label={t('Total')} value={money(o.total)} strong />
        </Card>
      </ScrollView>

      {next.length || (canComplete && ['ready', 'out_for_delivery', 'processing', 'confirmed'].includes(status)) ? (
        <View style={{ position: 'absolute', left: 0, right: 0, bottom: 0, padding: space.lg, paddingBottom: space.xxl, backgroundColor: c.surface, borderTopWidth: 1, borderTopColor: c.border, gap: space.sm }}>
          <View style={{ flexDirection: 'row', gap: space.sm }}>
            {next
              .filter((s) => s !== 'cancelled')
              .slice(0, 2)
              .map((s, i) => (
                <Button key={s} title={actionLabel[s] ?? t(ORDER_STATUS[s].label)} icon={ORDER_STATUS[s].icon} variant={i === 0 ? 'primary' : 'secondary'} style={{ flex: 1 }} onPress={() => move(s)} />
              ))}
          </View>
          <View style={{ flexDirection: 'row', gap: space.sm }}>
            {canComplete && ['ready', 'out_for_delivery', 'processing', 'confirmed'].includes(status) ? (
              <Button title={t('Complete & take payment')} icon="checkmark-done" variant="success" style={{ flex: 1 }} onPress={() => { setAmount(due); setPayOpen(true); }} />
            ) : null}
            {next.includes('cancelled') ? <Button title={t('Cancel')} variant="danger" icon="close" onPress={() => setCancelOpen(true)} /> : null}
          </View>
        </View>
      ) : null}

      <Sheet visible={cancelOpen} onClose={() => setCancelOpen(false)} title={t('Cancel order')} footer={<Button title={t('Cancel order')} variant="danger" onPress={doCancel} />}>
        <Input label={t('Reason (the customer may see it)')} value={reason} onChangeText={setReason} multiline style={{ minHeight: 80, textAlignVertical: 'top' }} autoFocus />
      </Sheet>

      <Sheet visible={payOpen} onClose={() => setPayOpen(false)} title={t('Complete order')} footer={<Button title={t('Complete order')} variant="success" icon="checkmark-done" onPress={doComplete} />}>
        <View style={{ gap: space.md }}>
          <Text tone="muted">{o.paid_online > 0 ? t('Paid online: {amount}. Take the rest now.', { amount: money(o.paid_online) }) : t('Take the payment and turn the order into a sale.')}</Text>
          <Segmented value={method} onChange={setMethod} options={[{ value: 'cash', label: t('Cash') }, { value: 'mobile_money', label: t('Mobile') }, { value: 'bank', label: t('Bank') }]} />
          <NumberInput label={t('Amount received')} value={amount} onChange={setAmount} icon="cash-outline" />
          {method !== 'cash' ? <Input label={t('Transaction ID / reference')} value={reference} onChangeText={setReference} autoCapitalize="characters" /> : null}
        </View>
      </Sheet>
    </Screen>
  );
}

function Line({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
      <Text variant={strong ? 'heading' : 'body'}>{label}</Text>
      <Text variant={strong ? 'heading' : 'bodyStrong'} numeric>
        {value}
      </Text>
    </View>
  );
}
