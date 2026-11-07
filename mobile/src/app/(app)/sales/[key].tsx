import { useEffect, useState } from 'react';
import { Animated, ScrollView, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { useLive } from '@/lib/useLive';
import { useSession } from '@/lib/session';
import { dateTime, money, qty } from '@/lib/format';
import { getCustomer, getSale } from '@/db/repo';
import { useT } from '@/i18n';
import { Badge, Button, Card, Divider, EmptyState, Screen, SkeletonList, Text, space, toast, useColors } from '@/ui';
import { SALE_SYNC, ScreenHeader } from '@/features/common';
import { printReceipt, receiptText, sendWhatsApp, shareReceiptPdf } from '@/features/receipt';

/** One sale: the receipt, its sync state, share / print. Right after checkout it opens with a tick. */
export default function SaleScreen() {
  const t = useT();
  const c = useColors();
  const { key, done } = useLocalSearchParams<{ key: string; done?: string }>();
  const settings = useSession((s) => s.settings);
  const sale = useLive(() => getSale(String(key)), ['sales'], [key]);
  const [pop] = useState(() => new Animated.Value(done ? 0 : 1));

  useEffect(() => {
    if (done) Animated.spring(pop, { toValue: 1, useNativeDriver: true, friction: 5, tension: 120 }).start();
  }, [done, pop]);

  const s = sale.data;
  const state = s ? SALE_SYNC[s.sync_state] : null;

  const whatsapp = async () => {
    if (!s) return;
    const customer = s.customer_key ? await getCustomer(s.customer_key) : null;
    await sendWhatsApp(receiptText(s, settings, t), customer?.phone);
  };

  return (
    <Screen padded={false} edges={[]}>
      <ScreenHeader title={s?.number ?? s?.local_number ?? t('Sale')} subtitle={s ? dateTime(s.sold_at) : undefined} />
      {sale.loading ? <SkeletonList rows={5} /> : !s ? (
        <EmptyState icon="receipt-outline" title={t('This sale is not on this phone.')} text={t('Older sales are on the web app.')} />
      ) : (
        <ScrollView contentContainerStyle={{ padding: space.lg, gap: space.lg, paddingBottom: 140 }}>
          {done ? (
            <Animated.View style={{ alignItems: 'center', gap: space.sm, transform: [{ scale: pop }], opacity: pop }}>
              <View style={{ width: 84, height: 84, borderRadius: 42, backgroundColor: c.accentSoft, alignItems: 'center', justifyContent: 'center' }}>
                <Ionicons name="checkmark-circle" size={70} color={c.accent} />
              </View>
              <Text variant="title">{t('Sale saved')}</Text>
              {s.change_given > 0 ? (
                <Text variant="heading" tone="accent">
                  {t('Give change: {amount}', { amount: money(s.change_given) })}
                </Text>
              ) : null}
              <Text tone="muted" center>
                {s.sync_state === 'synced' ? t('Saved on the server as {number}.', { number: s.number ?? '' }) : t('Saved on this phone. It is sent to the server automatically.')}
              </Text>
            </Animated.View>
          ) : null}

          {state ? (
            <Card style={{ flexDirection: 'row', alignItems: 'center', gap: space.md, backgroundColor: s.sync_state === 'attention' ? c.dangerSoft : undefined }}>
              <Badge label={t(state.label)} tone={state.tone} icon={state.icon} />
              <Text variant="caption" tone={s.sync_state === 'attention' ? 'danger' : 'muted'} style={{ flex: 1 }} numberOfLines={3}>
                {s.message ?? (s.sync_state === 'synced' ? t('On the server') : t('Sent automatically when online'))}
              </Text>
              {s.sync_state === 'attention' ? <Button title={t('Fix')} size="sm" variant="danger" onPress={() => router.push('/sync')} /> : null}
            </Card>
          ) : null}

          <Card style={{ gap: space.sm }}>
            <Text variant="heading" center>
              {settings.business_name ?? 'PatoPro'}
            </Text>
            {s.customer_name ? (
              <Text tone="muted" center>
                {t('Customer')}: {s.customer_name}
              </Text>
            ) : null}
            <Divider style={{ marginVertical: space.sm }} />
            {s.items.length ? (
              s.items.map((i, idx) => (
                <View key={idx} style={{ flexDirection: 'row', gap: space.sm }}>
                  <View style={{ flex: 1 }}>
                    <Text variant="bodyStrong">{i.name}</Text>
                    <Text variant="caption" tone="muted">
                      {qty(i.quantity)} × {money(i.unit_price, false)}
                      {i.unit ? ` / ${i.unit}` : ''}
                    </Text>
                  </View>
                  <Text variant="bodyStrong" numeric>
                    {money(i.line_total, false)}
                  </Text>
                </View>
              ))
            ) : (
              <Text tone="muted">{t('{n} items (details on the web app)', { n: s.items_count })}</Text>
            )}
            <Divider style={{ marginVertical: space.sm }} />
            <Row label={t('Total')} value={money(s.total)} strong />
            <Row label={t('Paid')} value={money(s.amount_paid, false)} />
            {s.change_given > 0 ? <Row label={t('Change')} value={money(s.change_given, false)} /> : null}
            {s.balance > 0 ? <Row label={t('Balance (credit)')} value={money(s.balance, false)} danger /> : null}
            <Text variant="caption" tone="faint" center style={{ marginTop: space.sm }}>
              Powered by SHEZONI PatoPro
            </Text>
          </Card>

          {s.items.length ? (
            <View style={{ flexDirection: 'row', gap: space.sm }}>
              <Button title={t('WhatsApp')} icon="logo-whatsapp" variant="secondary" style={{ flex: 1 }} onPress={whatsapp} />
              <Button title={t('PDF')} icon="share-outline" variant="secondary" style={{ flex: 1 }} onPress={() => shareReceiptPdf(s, settings, t).catch((e) => toast.error(String(e)))} />
              <Button title={t('Print')} icon="print-outline" variant="secondary" style={{ flex: 1 }} onPress={() => printReceipt(s, settings, t).catch(() => undefined)} />
            </View>
          ) : null}
          {done ? <Button title={t('New sale')} icon="add-circle" size="lg" onPress={() => router.back()} /> : null}
        </ScrollView>
      )}
    </Screen>
  );
}

function Row({ label, value, strong, danger }: { label: string; value: string; strong?: boolean; danger?: boolean }) {
  return (
    <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
      <Text variant={strong ? 'heading' : 'body'} tone={danger ? 'danger' : 'default'}>
        {label}
      </Text>
      <Text variant={strong ? 'heading' : 'bodyStrong'} tone={danger ? 'danger' : 'default'} numeric>
        {value}
      </Text>
    </View>
  );
}
