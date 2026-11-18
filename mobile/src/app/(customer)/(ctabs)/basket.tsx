import { useEffect, useState } from 'react';
import { Image, KeyboardAvoidingView, Platform, ScrollView, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ApiError, api } from '@/lib/api';
import { money } from '@/lib/format';
import { haptic } from '@/lib/haptics';
import { useSession } from '@/lib/session';
import { useT } from '@/i18n';
import { Button, Card, Chip, Divider, EmptyState, Header, IconButton, Input, Segmented, Stepper, Text, radius, space, toast, useColors } from '@/ui';
import { basketTotal, shopApi, useBasket, useRemote, type ShopOrder } from '@/features/shop/data';
import { useOrderAlerts } from '@/features/shop/alerts';

/** The basket and placing the order (pickup at a branch, or delivery). */
export default function Basket() {
  const t = useT();
  const c = useColors();
  const user = useSession((s) => s.user);
  const { items, branchId, setQuantity, remove, clear, setBranch } = useBasket();
  const branches = useRemote(() => shopApi.branches(), []);
  const [method, setMethod] = useState<'pickup' | 'delivery'>('pickup');
  const [address, setAddress] = useState('');
  const [phone, setPhone] = useState(user?.phone ?? '');
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const total = basketTotal(items);

  // One branch: chosen for the customer. Several: the first until they pick.
  useEffect(() => {
    const list = branches.data ?? [];
    if (list.length && !list.some((b) => b.id === branchId)) setBranch(list[0].id);
  }, [branches.data, branchId, setBranch]);

  const place = async () => {
    setError(null);
    if (!branchId) {
      setError(t('Choose the branch for your order.'));
      return;
    }
    if (method === 'delivery' && !address.trim()) {
      setError(t('Enter the address where we should deliver.'));
      return;
    }
    setBusy(true);
    try {
      const res = await api.post<ShopOrder>('shop/account/orders', {
        branch_id: branchId,
        items: items.map((i) => ({ product_id: i.product_id, quantity: i.quantity })),
        delivery_method: method,
        delivery_address: method === 'delivery' ? address.trim() : null,
        contact_phone: phone.trim() || null,
        customer_notes: notes.trim() || null,
      });
      clear();
      setNotes('');
      haptic.success();
      toast.success(res.message ?? t('Thank you! Your order has been received.'));
      useOrderAlerts.getState().bump();
      router.push(`/my-orders/${encodeURIComponent(res.data.number)}`);
    } catch (e) {
      setError(e instanceof ApiError ? (e.offline ? t('No internet. Your basket is kept: place the order when you are connected.') : e.firstError) : String(e));
      haptic.error();
    } finally {
      setBusy(false);
    }
  };

  if (!items.length) {
    return (
      <SafeAreaView edges={['top']} style={{ flex: 1, backgroundColor: c.bg, paddingHorizontal: space.lg }}>
        <Header title={t('Basket')} />
        <EmptyState icon="basket-outline" title={t('Your basket is empty')} text={t('Add products from the shop.')} action={{ title: t('Go to the shop'), onPress: () => router.navigate('/shop'), icon: 'storefront-outline' }} />
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView edges={['top']} style={{ flex: 1, backgroundColor: c.bg }}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ paddingHorizontal: space.lg, paddingBottom: space.xxxl, gap: space.lg }}>
          <Header title={t('Basket')} subtitle={t('{n} products', { n: items.length })} right={<IconButton icon="trash-outline" tone="danger" label={t('Empty the basket')} onPress={clear} />} />

          <Card padded={false}>
            {items.map((i, index) => (
              <View key={i.product_id}>
                {index > 0 ? <Divider inset={space.lg} /> : null}
                <View style={{ flexDirection: 'row', alignItems: 'center', gap: space.md, padding: space.md }}>
                  <View style={{ width: 56, height: 56, borderRadius: radius.md, backgroundColor: c.surfaceAlt, overflow: 'hidden', alignItems: 'center', justifyContent: 'center' }}>
                    {i.image_url ? <Image source={{ uri: i.image_url }} style={{ width: 56, height: 56 }} resizeMode="contain" /> : <Ionicons name="cube-outline" size={22} color={c.textFaint} />}
                  </View>
                  <View style={{ flex: 1, gap: 4 }}>
                    <Text variant="label" numberOfLines={2}>
                      {i.name}
                    </Text>
                    <Text variant="caption" tone="muted" numeric>
                      {money(i.price)}
                      {i.unit ? ` / ${i.unit}` : ''} · <Text variant="caption" tone="primary">{money(i.price * i.quantity)}</Text>
                    </Text>
                  </View>
                  <View style={{ alignItems: 'flex-end', gap: 4 }}>
                    <Stepper compact value={i.quantity} onChange={(n) => (n <= 0 ? remove(i.product_id) : setQuantity(i.product_id, n))} min={0} decimals={i.allow_decimal} />
                  </View>
                </View>
              </View>
            ))}
          </Card>

          {(branches.data?.length ?? 0) > 1 ? (
            <View style={{ gap: space.sm }}>
              <Text variant="tiny" tone="muted" style={{ textTransform: 'uppercase' }}>
                {t('Branch')}
              </Text>
              <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: space.sm }}>
                {branches.data!.map((b) => (
                  <Chip key={b.id} label={b.name} selected={b.id === branchId} onPress={() => setBranch(b.id)} icon="storefront-outline" />
                ))}
              </ScrollView>
            </View>
          ) : null}

          <Card style={{ gap: space.md }}>
            <Segmented
              value={method}
              onChange={setMethod}
              options={[
                { value: 'pickup', label: t('I will collect') },
                { value: 'delivery', label: t('Deliver to me') },
              ]}
            />
            {method === 'delivery' ? <Input label={t('Delivery address')} value={address} onChangeText={setAddress} icon="location-outline" placeholder={t('Street, area, landmark')} multiline /> : null}
            <Input label={t('Phone for this order')} value={phone} onChangeText={setPhone} icon="call-outline" keyboardType="phone-pad" />
            <Input label={t('Note to the shop (optional)')} value={notes} onChangeText={setNotes} icon="chatbox-ellipses-outline" multiline />
          </Card>

          <Card style={{ gap: space.sm }}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
              <Text tone="muted">{t('Total')}</Text>
              <Text variant="title" numeric>
                {money(total)}
              </Text>
            </View>
            {method === 'delivery' ? (
              <Text variant="caption" tone="faint">
                {t('The shop confirms the delivery fee, if any, when it confirms your order.')}
              </Text>
            ) : null}
          </Card>

          {error ? (
            <Text variant="label" tone="danger">
              {error}
            </Text>
          ) : null}
          <Button title={t('Place order')} size="lg" icon="checkmark-circle-outline" loading={busy} onPress={place} />
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}
