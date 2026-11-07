import { useState } from 'react';
import { Linking, ScrollView, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { useCan } from '@/lib/session';
import { useLive } from '@/lib/useLive';
import { dateTime, money, phoneIntl } from '@/lib/format';
import { getCustomer, listSales } from '@/db/repo';
import { receivePayment } from '@/db/commands';
import { syncSoon } from '@/sync/manager';
import { useCart } from '@/features/pos/cart';
import { useT } from '@/i18n';
import { Badge, Button, Card, EmptyState, Group, IconButton, Input, ListRow, NumberInput, Screen, Section, Segmented, Sheet, SkeletonList, StatTile, Text, space, toast } from '@/ui';
import { ScreenHeader } from '@/features/common';
import { sendWhatsApp } from '@/features/receipt';
import type { PaymentMethod } from '@/lib/types';

export default function CustomerScreen() {
  const t = useT();
  const { key } = useLocalSearchParams<{ key: string }>();
  const canEdit = useCan('customers.update');
  const canPay = useCan('sales.receive_payment');
  const canSell = useCan('pos.access');
  const customer = useLive(() => getCustomer(String(key)), ['customers'], [key]);
  const sales = useLive(async () => (await listSales(400)).filter((s) => s.customer_key === key || (customer.data?.id && s.customer_key === `s:${customer.data.id}`)).slice(0, 10), ['sales'], [key, customer.data?.id]);
  const [payOpen, setPayOpen] = useState(false);
  const [amount, setAmount] = useState<number | null>(null);
  const [method, setMethod] = useState<PaymentMethod>('cash');
  const [reference, setReference] = useState('');

  const cu = customer.data;
  if (customer.loading) return <Screen padded={false} edges={[]}><ScreenHeader title={t('Customer')} /><SkeletonList rows={4} /></Screen>;
  if (!cu) return <Screen padded={false} edges={[]}><ScreenHeader title={t('Customer')} /><EmptyState icon="person-outline" title={t('This customer is not on the phone')} /></Screen>;

  const pay = async () => {
    if (!amount || amount <= 0) return toast.warning(t('Enter the amount received.'));
    if (method !== 'cash' && !reference.trim()) return toast.warning(t('Enter the transaction ID / reference.'));
    await receivePayment(cu, amount, method, reference || null);
    setPayOpen(false);
    setAmount(null);
    setReference('');
    syncSoon();
    toast.success(t('Payment of {amount} recorded.', { amount: money(amount) }));
  };

  return (
    <Screen padded={false} edges={[]}>
      <ScreenHeader title={cu.name} subtitle={cu.code ?? undefined} right={canEdit ? <IconButton icon="create-outline" label={t('Edit')} onPress={() => router.push({ pathname: '/customers/edit', params: { key: cu.key } })} /> : undefined} />
      <ScrollView contentContainerStyle={{ padding: space.lg, gap: space.lg, paddingBottom: space.xxxl }}>
        {cu.pending ? <Badge label={t('Changes not synced yet')} tone="warning" icon="cloud-upload" /> : null}
        <View style={{ flexDirection: 'row', gap: space.sm }}>
          <StatTile icon="wallet" label={t('Owes')} value={money(cu.balance, false)} tone={cu.balance > 0 ? 'warning' : 'success'} />
          <StatTile icon="card" label={t('Credit limit')} value={cu.allow_credit ? (cu.credit_limit ? money(cu.credit_limit, false) : t('No limit')) : t('No credit')} tone="info" />
        </View>

        {cu.phone ? (
          <View style={{ flexDirection: 'row', gap: space.sm }}>
            <Button title={t('Call')} icon="call" variant="secondary" style={{ flex: 1 }} onPress={() => Linking.openURL(`tel:${cu.phone}`)} />
            <Button
              title="WhatsApp"
              icon="logo-whatsapp"
              variant="secondary"
              style={{ flex: 1 }}
              onPress={() => sendWhatsApp(cu.balance > 0 ? t('Hello {name}, a reminder: your balance is {amount}. Thank you.', { name: cu.name, amount: money(cu.balance) }) : t('Hello {name},', { name: cu.name }), phoneIntl(cu.phone))}
            />
          </View>
        ) : null}

        <View style={{ flexDirection: 'row', gap: space.sm }}>
          {canPay && cu.balance > 0 ? <Button title={t('Receive payment')} icon="cash" variant="success" style={{ flex: 1 }} onPress={() => { setAmount(cu.balance); setPayOpen(true); }} /> : null}
          {canSell ? (
            <Button
              title={t('Sell to this customer')}
              icon="cart"
              style={{ flex: 1 }}
              onPress={() => {
                useCart.getState().setCustomer(cu);
                router.navigate('/sell');
              }}
            />
          ) : null}
        </View>

        <Group>
          {cu.address || cu.city ? <ListRow icon="location-outline" title={t('Address')} subtitle={[cu.address, cu.city].filter(Boolean).join(', ')} chevron={false} /> : null}
          {cu.email ? <ListRow icon="mail-outline" title={t('Email')} subtitle={cu.email} chevron={false} /> : null}
          <ListRow icon="pricetags-outline" title={t('Customer type')} subtitle={t(cu.customer_type === 'wholesale' ? 'Wholesale' : cu.customer_type === 'institution' ? 'Institution' : 'Retail')} chevron={false} />
        </Group>

        <Section title={t('Recent sales')} style={{ marginTop: 0 }}>
          {sales.data?.length ? (
            <Group>
              {sales.data.map((s) => (
                <ListRow
                  key={s.key}
                  icon="receipt-outline"
                  title={s.number ?? s.local_number ?? ''}
                  subtitle={dateTime(s.sold_at)}
                  right={<Text variant="bodyStrong" numeric>{money(s.total, false)}</Text>}
                  onPress={() => router.push({ pathname: '/sales/[key]', params: { key: s.key } })}
                />
              ))}
            </Group>
          ) : (
            <Card>
              <Text tone="muted" center>
                {t('No recent sales on this phone.')}
              </Text>
            </Card>
          )}
        </Section>
      </ScrollView>

      <Sheet visible={payOpen} onClose={() => setPayOpen(false)} title={t('Receive payment')} footer={<Button title={t('Save payment')} icon="checkmark" variant="success" onPress={pay} />}>
        <View style={{ gap: space.md }}>
          <Text tone="muted">{t('{name} owes {amount}.', { name: cu.name, amount: money(cu.balance) })}</Text>
          <Segmented value={method} onChange={setMethod} options={[{ value: 'cash', label: t('Cash') }, { value: 'mobile_money', label: t('Mobile') }, { value: 'bank', label: t('Bank') }]} />
          <NumberInput label={t('Amount received')} value={amount} onChange={setAmount} icon="cash-outline" autoFocus />
          {method !== 'cash' ? <Input label={t('Transaction ID / reference')} value={reference} onChangeText={setReference} autoCapitalize="characters" /> : null}
        </View>
      </Sheet>
    </Screen>
  );
}
