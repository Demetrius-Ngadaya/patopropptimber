import { useState } from 'react';
import { Linking, ScrollView, View } from 'react-native';
import { useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ApiError, api } from '@/lib/api';
import { dateTime, money, phoneIntl, qty } from '@/lib/format';
import { useT } from '@/i18n';
import { Badge, Button, Card, Divider, EmptyState, Input, Sheet, SkeletonList, Text, space, toast, useColors } from '@/ui';
import { ScreenHeader } from '@/features/common';
import { CUSTOMER_STATUS, num, shopApi, useRemote, type OrderStatus } from '@/features/shop/data';
import { useOrderAlerts } from '@/features/shop/alerts';

const STEPS: OrderStatus[] = ['pending', 'confirmed', 'processing', 'ready', 'completed'];

/** One order: where it is, what is in it, and cancelling while it is still new. */
export default function MyOrder() {
  const t = useT();
  const c = useColors();
  const { number } = useLocalSearchParams<{ number: string }>();
  const version = useOrderAlerts((s) => s.version);
  const order = useRemote(() => shopApi.order(String(number)), [number, version]);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const o = order.data;

  if (!o) {
    return (
      <View style={{ flex: 1, backgroundColor: c.bg }}>
        <ScreenHeader title={String(number)} />
        {order.error ? <EmptyState icon="cloud-offline-outline" tone="warning" title={order.error.offline ? t('No internet') : t('Could not load the order')} text={order.error.message} action={{ title: t('Try again'), onPress: order.reload, icon: 'refresh' }} /> : <SkeletonList rows={5} />}
      </View>
    );
  }

  const s = CUSTOMER_STATUS[o.status] ?? CUSTOMER_STATUS.pending;
  const steps = o.delivery_method === 'delivery' ? STEPS.map((x) => (x === 'ready' ? 'out_for_delivery' : x)) : STEPS;
  const reached = o.status === 'cancelled' ? -1 : Math.max(steps.indexOf(o.status), 0);

  const cancel = async () => {
    setBusy(true);
    try {
      await api.post(`shop/account/orders/${encodeURIComponent(o.number)}/cancel`, { reason: reason.trim() || null });
      toast.success(t('Your order has been cancelled.'));
      setCancelOpen(false);
      order.reload();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.firstError : String(e));
    } finally {
      setBusy(false);
    }
  };

  const shopPhone = phoneIntl(o.branch?.phone);

  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <ScreenHeader title={o.number} subtitle={dateTime(o.created_at)} />
      <ScrollView contentContainerStyle={{ padding: space.lg, gap: space.lg, paddingBottom: space.xxxl }}>
        <Card style={{ gap: space.md }}>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: space.md }}>
            <Ionicons name={s.icon} size={30} color={o.status === 'cancelled' ? c.danger : c.primary} />
            <View style={{ flex: 1 }}>
              <Text variant="heading">{t(s.label)}</Text>
              <Text variant="caption" tone="muted">
                {o.status === 'cancelled' && o.cancel_reason ? o.cancel_reason : t(s.hint)}
              </Text>
            </View>
          </View>
          {o.status !== 'cancelled' ? (
            <View style={{ flexDirection: 'row', alignItems: 'center' }}>
              {steps.map((step, i) => (
                <View key={step} style={{ flex: 1, flexDirection: 'row', alignItems: 'center' }}>
                  <View style={{ width: 14, height: 14, borderRadius: 7, backgroundColor: i <= reached ? c.primary : c.border }} />
                  {i < steps.length - 1 ? <View style={{ flex: 1, height: 3, backgroundColor: i < reached ? c.primary : c.border }} /> : null}
                </View>
              ))}
            </View>
          ) : null}
        </Card>

        <Card padded={false}>
          {(o.items ?? []).map((it, i) => (
            <View key={it.id}>
              {i > 0 ? <Divider inset={space.lg} /> : null}
              <View style={{ flexDirection: 'row', padding: space.md, gap: space.md }}>
                <Text variant="label" style={{ flex: 1 }}>
                  {qty(num(it.quantity))} × {it.product_name}
                </Text>
                <Text variant="label" numeric>
                  {money(num(it.line_total))}
                </Text>
              </View>
            </View>
          ))}
          <Divider />
          <View style={{ padding: space.md, gap: 6 }}>
            {num(o.delivery_fee) > 0 ? (
              <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                <Text tone="muted">{t('Delivery fee')}</Text>
                <Text numeric>{money(num(o.delivery_fee))}</Text>
              </View>
            ) : null}
            <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
              <Text variant="bodyStrong">{t('Total')}</Text>
              <Text variant="heading" numeric>
                {money(num(o.total))}
              </Text>
            </View>
          </View>
        </Card>

        <Card style={{ gap: space.sm }}>
          <Badge label={o.delivery_method === 'delivery' ? t('Delivery') : t('Collect at {branch}', { branch: o.branch?.name ?? t('the shop') })} icon={o.delivery_method === 'delivery' ? 'bicycle' : 'storefront-outline'} tone="info" />
          {o.delivery_address ? <Text>{o.delivery_address}</Text> : null}
          {o.branch?.address && o.delivery_method === 'pickup' ? <Text tone="muted">{o.branch.address}</Text> : null}
          {o.customer_notes ? (
            <Text variant="caption" tone="muted">
              {t('Your note')}: {o.customer_notes}
            </Text>
          ) : null}
        </Card>

        {shopPhone ? <Button title={t('Call the shop')} icon="call-outline" variant="secondary" onPress={() => Linking.openURL(`tel:${shopPhone}`)} /> : null}
        {o.status === 'pending' ? <Button title={t('Cancel order')} icon="close-circle-outline" variant="danger" onPress={() => setCancelOpen(true)} /> : null}
      </ScrollView>

      <Sheet visible={cancelOpen} onClose={() => setCancelOpen(false)} title={t('Cancel order')} footer={<Button title={t('Cancel order')} variant="danger" loading={busy} onPress={cancel} />}>
        <Input label={t('Reason (optional)')} value={reason} onChangeText={setReason} multiline />
      </Sheet>
    </View>
  );
}
