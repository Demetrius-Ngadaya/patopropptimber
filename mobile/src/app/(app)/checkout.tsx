import { useEffect, useMemo, useState } from 'react';
import { haptic } from '@/lib/haptics';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, View } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useCan, useSession } from '@/lib/session';
import { useDebounced, useLive } from '@/lib/useLive';
import { money, qty, round2 } from '@/lib/format';
import { listCustomers } from '@/db/repo';
import { recordSale, saleTotals } from '@/db/commands';
import { syncSoon } from '@/sync/manager';
import { priceLines, toSaleLines, useCart } from '@/features/pos/cart';
import { useT } from '@/i18n';
import { Badge, Button, Card, Chip, Divider, EmptyState, IconButton, Input, ListRow, NumberInput, SearchBar, Segmented, Sheet, Stepper, Text, radius, space, toast, useColors } from '@/ui';
import type { PaymentMethod } from '@/lib/types';

type Method = PaymentMethod | 'credit';

function addDays(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

/** Review the cart, choose the customer and payment, finish the sale (works offline). */
export default function Checkout() {
  const t = useT();
  const c = useColors();
  const insets = useSafeAreaInsets();
  const settings = useSession((s) => s.settings);
  const canDiscount = useCan('pos.discount');
  const canCredit = useCan('pos.credit_sale');
  const canWholesale = useCan('pos.wholesale');
  const cart = useCart();
  const [method, setMethod] = useState<Method>('cash');
  const [received, setReceived] = useState<number | null>(null);
  const [reference, setReference] = useState('');
  const [dueDate, setDueDate] = useState(addDays(Number(settings.credit_default_days ?? 7)));
  const [pickCustomer, setPickCustomer] = useState(false);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    useCart.getState().setAutoWholesale(settings.auto_wholesale_by_quantity !== false);
  }, [settings.auto_wholesale_by_quantity]);

  const priced = useMemo(() => priceLines(cart), [cart]);
  const tax = settings.prices_include_tax === false ? round2(priced.reduce((a, l) => a + round2((l.lineTotal * (l.product.tax_rate || 0)) / 100), 0)) : 0;
  const lines = toSaleLines(priced);
  const base = saleTotals(lines, cart.discount, [], tax);
  const total = base.total;
  const amount = method === 'credit' ? 0 : received ?? total;
  const payments = method === 'credit' ? [] : [{ method: method as PaymentMethod, amount, reference: reference || null }];
  const totals = saleTotals(lines, cart.discount, payments, tax);
  const overStock = priced.filter((l) => l.overStock);
  const blockedByStock = overStock.length > 0 && !settings.allow_negative_stock;
  const needsCustomer = totals.balance > 0.004 && !cart.customer;
  const needsReference = (method === 'mobile_money' || method === 'bank' || method === 'card') && !reference.trim();

  const quick = useMemo(() => {
    const steps = [1000, 5000, 10000];
    const list = new Set<number>([total]);
    steps.forEach((s) => list.add(Math.ceil(total / s) * s));
    return [...list].filter((v) => v >= total).sort((a, b) => a - b).slice(0, 4);
  }, [total]);

  const problem = !cart.lines.length
    ? t('The cart is empty.')
    : blockedByStock
      ? t('Not enough stock for {name}.', { name: overStock[0].product.name })
      : needsReference
        ? t('Enter the transaction ID / reference.')
        : totals.balance > 0.004 && !canCredit
          ? t('Take the full amount: you cannot sell on credit.')
        : needsCustomer
          ? t('Choose the customer who will pay the balance.')
          : null;

  const finish = async () => {
    if (problem) {
      haptic.warning();
      toast.warning(problem);
      return;
    }
    setSaving(true);
    try {
      const key = await recordSale({
        lines,
        customer: cart.customer,
        payments,
        discount: cart.discount,
        tax,
        dueDate: totals.balance > 0.004 ? dueDate : null,
        notes: null,
      });
      haptic.success();
      useCart.getState().clear();
      syncSoon();
      router.dismissTo('/sell');
      router.push({ pathname: '/sales/[key]', params: { key, done: '1' } });
    } catch (e) {
      toast.error(e instanceof Error ? e.message : String(e));
    } finally {
      setSaving(false);
    }
  };

  const methods: { value: Method; label: string }[] = [
    { value: 'cash', label: t('Cash') },
    { value: 'mobile_money', label: t('Mobile') },
    { value: 'bank', label: t('Bank') },
    ...(canCredit ? [{ value: 'credit' as Method, label: t('Credit') }] : []),
  ];

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1, backgroundColor: c.bg }}>
      <View style={{ paddingTop: Platform.OS === 'android' ? insets.top : space.sm, flexDirection: 'row', alignItems: 'center', paddingHorizontal: space.sm, height: 56 + (Platform.OS === 'android' ? insets.top : space.sm) }}>
        <IconButton icon="close" label={t('Close')} onPress={() => router.back()} />
        <Text variant="heading" style={{ flex: 1 }}>
          {t('Checkout')}
        </Text>
        {cart.lines.length ? <Button title={t('Clear')} variant="ghost" size="sm" onPress={() => { useCart.getState().clear(); router.back(); }} /> : null}
      </View>

      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: space.lg, gap: space.lg, paddingBottom: 160 }}>
        {/* Items */}
        <Card padded={false}>
          {priced.length === 0 ? <EmptyState icon="cart-outline" title={t('The cart is empty.')} /> : null}
          {priced.map((l, i) => (
            <View key={l.key}>
              {i > 0 ? <Divider inset={space.lg} /> : null}
              <View style={{ padding: space.lg, gap: space.sm }}>
                <View style={{ flexDirection: 'row', gap: space.sm }}>
                  <View style={{ flex: 1 }}>
                    <Text variant="bodyStrong" numberOfLines={2}>
                      {l.product.name}
                    </Text>
                    <Text variant="caption" tone="muted">
                      {money(l.unitPrice)} / {l.unit?.name ?? l.product.unit ?? t('piece')}
                    </Text>
                  </View>
                  <Text variant="bodyStrong" numeric>
                    {money(l.lineTotal, false)}
                  </Text>
                </View>
                <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
                  <Stepper value={l.quantity} decimals={l.product.allow_decimal} min={0} onChange={(n) => useCart.getState().setQuantity(l.key, n)} compact />
                  <View style={{ flexDirection: 'row', alignItems: 'center', gap: space.sm }}>
                    {l.level === 'wholesale' ? <Badge label={t('Wholesale')} tone="success" /> : null}
                    {l.overStock ? <Badge label={t('Only {n} left', { n: qty(l.product.stock) })} tone="danger" /> : null}
                    <IconButton icon="trash-outline" label={t('Remove')} tone="danger" size={34} onPress={() => useCart.getState().remove(l.key)} />
                  </View>
                </View>
              </View>
            </View>
          ))}
        </Card>

        {/* Customer */}
        <Card padded={false}>
          <ListRow
            icon={cart.customer ? 'person' : 'person-add-outline'}
            iconTone={cart.customer ? 'primary' : 'neutral'}
            title={cart.customer?.name ?? t('Customer')}
            subtitle={cart.customer ? [cart.customer.phone, cart.customer.balance > 0 ? t('Owes {amount}', { amount: money(cart.customer.balance) }) : null].filter(Boolean).join(' · ') : t('Tap to choose a customer (needed for credit)')}
            onPress={() => setPickCustomer(true)}
            right={cart.customer ? <IconButton icon="close-circle" label={t('Remove customer')} onPress={() => useCart.getState().setCustomer(null)} /> : undefined}
            chevron={!cart.customer}
          />
          {canWholesale ? (
            <View style={{ paddingHorizontal: space.lg, paddingBottom: space.lg }}>
              <Segmented
                value={cart.chosenLevel ?? 'auto'}
                onChange={(v) => useCart.getState().setLevel(v === 'auto' ? null : v)}
                options={[
                  { value: 'auto', label: t('Auto price') },
                  { value: 'retail', label: t('Retail') },
                  { value: 'wholesale', label: t('Wholesale') },
                ]}
              />
            </View>
          ) : null}
        </Card>

        {/* Payment */}
        <Card style={{ gap: space.md }}>
          <Text variant="label" tone="muted">
            {t('Payment')}
          </Text>
          <Segmented value={method} onChange={(m) => { setMethod(m); setReceived(null); }} options={methods} />
          {method === 'credit' ? (
            <View style={{ gap: space.sm }}>
              <Text tone="muted">{t('The whole amount is recorded as the customer\'s debt.')}</Text>
              <Text variant="label" tone="muted">
                {t('Pay by')}
              </Text>
              <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: space.sm }}>
                {[7, 14, 30].map((d) => (
                  <Chip key={d} label={t('{n} days', { n: d })} selected={dueDate === addDays(d)} onPress={() => setDueDate(addDays(d))} />
                ))}
              </View>
              <Input value={dueDate} onChangeText={setDueDate} placeholder="YYYY-MM-DD" icon="calendar-outline" keyboardType="numbers-and-punctuation" />
            </View>
          ) : (
            <>
              <NumberInput label={method === 'cash' ? t('Amount received') : t('Amount paid')} value={received ?? total} onChange={setReceived} icon="cash-outline" />
              {method === 'cash' ? (
                <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: space.sm }}>
                  {quick.map((v) => (
                    <Chip key={v} label={v === total ? t('Exact') : money(v, false)} selected={(received ?? total) === v} onPress={() => setReceived(v)} />
                  ))}
                </View>
              ) : (
                <Input label={t('Transaction ID / reference')} value={reference} onChangeText={setReference} autoCapitalize="characters" icon="key-outline" placeholder="e.g. QK7XH2P1" />
              )}
              {totals.balance > 0.004 ? (
                <View style={{ gap: space.sm }}>
                  <Badge label={t('Balance {amount} goes on credit', { amount: money(totals.balance) })} tone="warning" icon="alert-circle" />
                  {!canCredit ? <Text variant="caption" tone="danger">{t('You are not allowed to sell on credit: take the full amount.')}</Text> : null}
                </View>
              ) : null}
            </>
          )}
          {canDiscount ? <NumberInput label={t('Discount')} value={cart.discount || null} onChange={(n) => useCart.getState().setDiscount(n ?? 0)} icon="pricetag-outline" placeholder="0" /> : null}
        </Card>
      </ScrollView>

      {/* Totals + finish */}
      <View style={{ position: 'absolute', left: 0, right: 0, bottom: 0, backgroundColor: c.surface, borderTopWidth: 1, borderTopColor: c.border, padding: space.lg, paddingBottom: insets.bottom + space.md, gap: space.md }}>
        <View style={{ flexDirection: 'row', alignItems: 'flex-end' }}>
          <View style={{ flex: 1 }}>
            <Text variant="caption" tone="muted">
              {t('Total')}
              {tax ? ` · ${t('incl. tax {amount}', { amount: money(tax, false) })}` : ''}
              {cart.discount ? ` · ${t('discount {amount}', { amount: money(cart.discount, false) })}` : ''}
            </Text>
            <Text variant="title" numeric>
              {money(total)}
            </Text>
          </View>
          {totals.change > 0 ? (
            <Pressable style={{ alignItems: 'flex-end' }}>
              <Text variant="caption" tone="muted">
                {t('Change')}
              </Text>
              <Text variant="title" tone="accent" numeric>
                {money(totals.change, false)}
              </Text>
            </Pressable>
          ) : null}
        </View>
        <Button title={problem ?? t('Complete sale')} size="lg" variant={problem ? 'secondary' : 'success'} icon={problem ? 'alert-circle-outline' : 'checkmark-circle'} loading={saving} onPress={finish} />
      </View>

      <CustomerPicker visible={pickCustomer} onClose={() => setPickCustomer(false)} />
    </KeyboardAvoidingView>
  );
}

function CustomerPicker({ visible, onClose }: { visible: boolean; onClose: () => void }) {
  const t = useT();
  const [search, setSearch] = useState('');
  const term = useDebounced(search, 150);
  const customers = useLive(() => listCustomers(term, 40), ['customers'], [term, visible]);
  return (
    <Sheet visible={visible} onClose={onClose} title={t('Choose customer')} height="85%">
      <View style={{ gap: space.md }}>
        <SearchBar value={search} onChangeText={setSearch} placeholder={t('Name, phone or code')} />
        <Button
          title={t('New customer')}
          icon="person-add-outline"
          variant="secondary"
          onPress={() => {
            onClose();
            router.push({ pathname: '/customers/edit', params: { pick: '1', name: search } });
          }}
        />
        <View style={{ marginHorizontal: -space.lg }}>
          {(customers.data ?? []).map((cu) => (
            <ListRow
              key={cu.key}
              icon="person-outline"
              title={cu.name}
              subtitle={[cu.phone, cu.balance > 0 ? t('Owes {amount}', { amount: money(cu.balance) }) : null, cu.pending ? t('Not synced yet') : null].filter(Boolean).join(' · ')}
              onPress={() => {
                useCart.getState().setCustomer(cu);
                onClose();
              }}
              chevron={false}
              right={cu.customer_type === 'wholesale' ? <Badge label={t('Wholesale')} tone="success" /> : undefined}
            />
          ))}
        </View>
        {customers.data && !customers.data.length ? <Text tone="muted" center>{t('No customer found.')}</Text> : null}
        <View style={{ height: space.lg }} />
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6, justifyContent: 'center' }}>
          <Ionicons name="information-circle-outline" size={14} color="#8A98AD" />
          <Text variant="caption" tone="faint">
            {t('Customers added offline are sent with the next sync.')}
          </Text>
        </View>
        <View style={{ height: radius.md }} />
      </View>
    </Sheet>
  );
}
