import { ScrollView, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { useCan } from '@/lib/session';
import { useLive } from '@/lib/useLive';
import { money, qty } from '@/lib/format';
import { getProduct, unitsFor } from '@/db/repo';
import { useCart } from '@/features/pos/cart';
import { useT } from '@/i18n';
import { Badge, Button, Card, EmptyState, Group, ListRow, Screen, SkeletonList, StatTile, Text, space, toast } from '@/ui';
import { ScreenHeader } from '@/features/common';

export default function ProductScreen() {
  const t = useT();
  const { id } = useLocalSearchParams<{ id: string }>();
  const canSell = useCan('pos.access');
  const product = useLive(() => getProduct(Number(id)), ['products', 'sales'], [id]);
  const units = useLive(() => unitsFor(Number(id)), ['products'], [id]);
  const p = product.data;

  if (product.loading) return <Screen padded={false} edges={[]}><ScreenHeader title={t('Product')} /><SkeletonList rows={3} /></Screen>;
  if (!p) return <Screen padded={false} edges={[]}><ScreenHeader title={t('Product')} /><EmptyState icon="cube-outline" title={t('This product is not on the phone')} text={t('It may be inactive or deleted.')} /></Screen>;

  const out = !p.is_service && p.stock <= 0;
  const low = !p.is_service && !out && p.stock <= (p.reorder_level ?? 0);

  return (
    <Screen padded={false} edges={[]}>
      <ScreenHeader title={p.name} subtitle={p.sku ?? undefined} />
      <ScrollView contentContainerStyle={{ padding: space.lg, gap: space.lg, paddingBottom: 120 }}>
        <View style={{ flexDirection: 'row', gap: space.sm }}>
          <StatTile icon="pricetag" label={t('Retail price')} value={money(p.selling_price, false)} tone="primary" />
          {p.is_service ? (
            <StatTile icon="construct" label={t('Service')} value="—" tone="info" />
          ) : (
            <StatTile icon="cube" label={t('In stock')} value={`${qty(p.stock)} ${p.unit ?? ''}`.trim()} tone={out ? 'danger' : low ? 'warning' : 'success'} hint={p.reorder_level ? t('Reorder at {n}', { n: qty(p.reorder_level) }) : undefined} />
          )}
        </View>
        {out ? <Badge label={t('Out of stock')} tone="danger" icon="alert-circle" /> : low ? <Badge label={t('Low stock: time to reorder')} tone="warning" icon="trending-down" /> : null}

        <Group>
          {p.wholesale_price !== null ? <ListRow icon="layers-outline" title={t('Wholesale price')} subtitle={p.wholesale_min_qty ? t('From {n} pieces', { n: qty(p.wholesale_min_qty) }) : undefined} right={<Text variant="bodyStrong" numeric>{money(p.wholesale_price)}</Text>} chevron={false} /> : null}
          {p.min_selling_price !== null ? <ListRow icon="arrow-down-circle-outline" iconTone="warning" title={t('Lowest price allowed')} right={<Text variant="bodyStrong" numeric>{money(p.min_selling_price)}</Text>} chevron={false} /> : null}
          {p.tax_rate > 0 ? <ListRow icon="receipt-outline" iconTone="info" title={t('Tax')} right={<Text variant="bodyStrong">{p.tax_rate}%</Text>} chevron={false} /> : null}
          <ListRow icon="barcode-outline" iconTone="neutral" title={t('Barcode')} subtitle={p.barcode ?? t('None')} chevron={false} />
        </Group>

        {units.data?.length ? (
          <Card style={{ gap: space.sm }}>
            <Text variant="label" tone="muted">
              {t('Packs')}
            </Text>
            {units.data.map((u) => (
              <View key={u.id} style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                <Text>
                  {u.name} · {t('{n} pieces', { n: qty(u.factor) })}
                </Text>
                <Text variant="bodyStrong" numeric>
                  {money(u.retail_price)}
                </Text>
              </View>
            ))}
          </Card>
        ) : null}

        {canSell ? (
          <Button
            title={t('Add to cart')}
            icon="cart"
            size="lg"
            onPress={() => {
              useCart.getState().add(p);
              toast.success(t('{name} added to the cart', { name: p.name }));
              router.navigate('/sell');
            }}
          />
        ) : null}
      </ScrollView>
    </Screen>
  );
}
