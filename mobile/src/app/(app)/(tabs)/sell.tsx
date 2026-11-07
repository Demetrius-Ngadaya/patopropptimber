import { useMemo, useState } from 'react';
import { haptic } from '@/lib/haptics';
import { Animated, FlatList, Pressable, ScrollView, View } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { useSession } from '@/lib/session';
import { useDebounced, useLive } from '@/lib/useLive';
import { money, qty } from '@/lib/format';
import { listCategories, listProducts, unitsFor } from '@/db/repo';
import { priceLines, useCart } from '@/features/pos/cart';
import { useT } from '@/i18n';
import { Badge, Chip, EmptyState, Header, ListRow, Screen, SearchBar, Sheet, Skeleton, Text, radius, shadow, space, useColors } from '@/ui';
import { OfflineBanner, SyncPill } from '@/features/common';
import type { Product, ProductUnit } from '@/lib/types';

/** The till: most-sold first, search, scan, categories (if the business shows them). */
export default function Sell() {
  const t = useT();
  const c = useColors();
  const settings = useSession((s) => s.settings);
  const showCategories = settings.pos_show_categories !== false;
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState<number | null>(null);
  const [unitPick, setUnitPick] = useState<{ product: Product; units: ProductUnit[] } | null>(null);
  const term = useDebounced(search, 150);
  const cart = useCart();
  const priced = useMemo(() => priceLines(cart), [cart]);
  const total = priced.reduce((a, l) => a + l.lineTotal, 0) - cart.discount;
  const items = cart.lines.reduce((a, l) => a + l.quantity, 0);
  const [bump] = useState(() => new Animated.Value(1));

  const products = useLive(() => listProducts({ search: term, categoryId: category, limit: 80 }), ['products', 'sales'], [term, category]);
  const categories = useLive(listCategories, ['categories', 'products'], []);

  const add = async (product: Product, unit: ProductUnit | null = null) => {
    haptic.tap();
    useCart.getState().add(product, unit);
    Animated.sequence([
      Animated.timing(bump, { toValue: 1.08, duration: 90, useNativeDriver: true }),
      Animated.spring(bump, { toValue: 1, useNativeDriver: true, friction: 4 }),
    ]).start();
  };

  const longPress = async (product: Product) => {
    const units = await unitsFor(product.id);
    if (units.length) setUnitPick({ product, units });
    else add(product);
  };

  const inCart = (id: number) => cart.lines.filter((l) => l.product.id === id).reduce((a, l) => a + l.quantity, 0);

  return (
    <Screen padded={false}>
      <View style={{ paddingHorizontal: space.lg }}>
        <Header title={t('Sell')} subtitle={t('Tap to add · hold for packs')} right={<SyncPill />} />
        <SearchBar value={search} onChangeText={setSearch} placeholder={t('Search name, code or barcode')} onScan={() => router.push('/scan')} />
      </View>
      <OfflineBanner />

      {showCategories && (categories.data?.length ?? 0) > 0 ? (
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: space.sm, paddingHorizontal: space.lg, paddingVertical: space.md }} style={{ flexGrow: 0, flexShrink: 0 }}>
          <Chip label={t('Most sold')} icon="flame" selected={category === null} onPress={() => setCategory(null)} />
          {categories.data!.map((cat) => (
            <Chip key={cat.id} label={cat.name} selected={category === cat.id} onPress={() => setCategory(category === cat.id ? null : cat.id)} />
          ))}
        </ScrollView>
      ) : (
        <View style={{ paddingHorizontal: space.lg, paddingVertical: space.sm }}>
          <Text variant="tiny" tone="muted" style={{ textTransform: 'uppercase' }}>
            {term ? t('Results') : t('Frequently sold here')}
          </Text>
        </View>
      )}

      {products.loading ? (
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: space.sm, paddingHorizontal: space.lg }}>
          {Array.from({ length: 8 }, (_, i) => (
            <Skeleton key={i} width="48.5%" height={96} round={radius.lg} />
          ))}
        </View>
      ) : (
        <FlatList
          data={products.data}
          keyExtractor={(p) => String(p.id)}
          numColumns={2}
          columnWrapperStyle={{ gap: space.sm, paddingHorizontal: space.lg }}
          contentContainerStyle={{ gap: space.sm, paddingBottom: 120 }}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="on-drag"
          ListEmptyComponent={
            <EmptyState
              icon={term ? 'search' : 'cube-outline'}
              title={term ? t('Nothing matches "{q}"', { q: term }) : t('No products on this phone yet')}
              text={term ? t('Try another word, or scan the barcode.') : t('Pull down on Home to sync, or check Settings → Sync.')}
            />
          }
          renderItem={({ item: p }) => {
            const n = inCart(p.id);
            const out = !p.is_service && p.stock <= 0;
            const low = !p.is_service && !out && p.stock <= (p.reorder_level ?? 0);
            return (
              <Pressable
                onPress={() => add(p)}
                onLongPress={() => longPress(p)}
                testID={`product-${p.id}`}
                accessibilityRole="button"
                accessibilityLabel={`${p.name}, ${money(p.selling_price)}${out ? `, ${t('Out of stock')}` : ''}${n ? `, ${t('{n} in cart', { n: qty(n) })}` : ''}`}
                accessibilityHint={t('Tap to add · hold for packs')}
                delayLongPress={350}
                style={({ pressed }) => [
                  { flex: 1, minHeight: 104, borderRadius: radius.lg, padding: space.md, backgroundColor: pressed ? c.surfacePressed : c.surface, borderWidth: n ? 2 : 1, borderColor: n ? c.primary : c.border, gap: 6, justifyContent: 'space-between' },
                ]}
              >
                <Text variant="bodyStrong" numberOfLines={2}>
                  {p.name}
                </Text>
                <View style={{ gap: 4 }}>
                  <Text variant="heading" tone="primary" numeric>
                    {money(p.selling_price, false)}
                  </Text>
                  <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
                    {p.is_service ? (
                      <Badge label={t('Service')} tone="info" />
                    ) : (
                      <Badge label={out ? t('Out of stock') : `${qty(p.stock)} ${p.unit ?? ''}`.trim()} tone={out ? 'danger' : low ? 'warning' : 'neutral'} />
                    )}
                    {n ? (
                      <View style={{ minWidth: 24, height: 24, borderRadius: 12, backgroundColor: c.primary, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 6 }}>
                        <Text variant="caption" style={{ color: '#fff', fontWeight: '800' }}>
                          {qty(n)}
                        </Text>
                      </View>
                    ) : null}
                  </View>
                </View>
              </Pressable>
            );
          }}
        />
      )}

      {cart.lines.length ? (
        <Animated.View style={[{ position: 'absolute', left: space.lg, right: space.lg, bottom: space.lg, transform: [{ scale: bump }] }, shadow(c, 3)]}>
          <Pressable
            onPress={() => router.push('/checkout')}
            testID="cart-bar"
            accessibilityRole="button"
            accessibilityLabel={`${t('Review and take payment')}: ${money(total)}`}
            style={({ pressed }) => ({ flexDirection: 'row', alignItems: 'center', gap: space.md, height: 62, borderRadius: radius.lg, paddingHorizontal: space.lg, backgroundColor: pressed ? c.primaryPressed : c.primary })}
          >
            <View style={{ width: 36, height: 36, borderRadius: 18, backgroundColor: 'rgba(255,255,255,0.18)', alignItems: 'center', justifyContent: 'center' }}>
              <Text variant="bodyStrong" style={{ color: '#fff' }}>
                {qty(items)}
              </Text>
            </View>
            <View style={{ flex: 1 }}>
              <Text variant="caption" style={{ color: 'rgba(255,255,255,0.8)' }}>
                {t('Review and take payment')}
              </Text>
              <Text variant="heading" style={{ color: '#fff' }} numeric>
                {money(total)}
              </Text>
            </View>
            <Ionicons name="arrow-forward-circle" size={32} color="#fff" />
          </Pressable>
        </Animated.View>
      ) : null}

      <Sheet visible={!!unitPick} onClose={() => setUnitPick(null)} title={unitPick?.product.name}>
        <ListRow
          title={`${t('Single')} (${unitPick?.product.unit ?? t('piece')})`}
          right={<Text variant="bodyStrong" numeric>{money(unitPick?.product.selling_price ?? 0)}</Text>}
          chevron={false}
          onPress={() => {
            if (unitPick) add(unitPick.product);
            setUnitPick(null);
          }}
        />
        {unitPick?.units.map((u) => (
          <ListRow
            key={u.id}
            title={u.name}
            subtitle={t('{n} pieces', { n: qty(u.factor) })}
            right={<Text variant="bodyStrong" numeric>{money(u.retail_price)}</Text>}
            chevron={false}
            onPress={() => {
              add(unitPick.product, u);
              setUnitPick(null);
            }}
          />
        ))}
      </Sheet>
    </Screen>
  );
}
