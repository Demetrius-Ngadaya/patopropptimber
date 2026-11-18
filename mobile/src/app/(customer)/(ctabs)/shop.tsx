import { useMemo, useState } from 'react';
import { FlatList, Image, Pressable, RefreshControl, ScrollView, View, useWindowDimensions } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { money } from '@/lib/format';
import { haptic } from '@/lib/haptics';
import { useSession } from '@/lib/session';
import { useDebounced } from '@/lib/useLive';
import { useT } from '@/i18n';
import { Chip, EmptyState, IconButton, SearchBar, Skeleton, Text, radius, shadow, space, toast, useColors } from '@/ui';
import { basketCount, num, shopApi, useBasket, useRemote, type ShopProduct } from '@/features/shop/data';

/** The shop: search, categories and products to tap into the basket. */
export default function Shop() {
  const t = useT();
  const c = useColors();
  const { width } = useWindowDimensions();
  const user = useSession((s) => s.user);
  const business = useSession((s) => s.settings.business_name);
  const branchId = useBasket((s) => s.branchId);
  const add = useBasket((s) => s.add);
  const inBasket = useBasket((s) => basketCount(s.items));
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [items, setItems] = useState<ShopProduct[]>([]);
  const term = useDebounced(search, 350);

  const categories = useRemote(() => shopApi.categories(), []);
  const list = useRemote(async () => {
    const res = await shopApi.products({ search: term || undefined, category, page, branch_id: branchId });
    setItems((prev) => (page === 1 ? res.data : [...prev, ...res.data.filter((p) => !prev.some((x) => x.id === p.id))]));
    return res;
  }, [term, category, page, branchId]);
  const lastPage = Number((list.meta as { last_page?: number } | undefined)?.last_page ?? 1);

  const columns = width >= 700 ? 3 : 2;
  const cardWidth = (width - space.lg * 2 - space.md * (columns - 1)) / columns;
  const firstName = (user?.name ?? '').split(' ')[0];

  const choose = (slug: string | null) => {
    setCategory(slug);
    setPage(1);
  };
  const quickAdd = (p: ShopProduct) => {
    if (!p.in_stock) return;
    add(p, 1);
    haptic.success();
    toast.success(t('{name} added to the basket', { name: p.name }));
  };

  const header = useMemo(
    () => (
      <View style={{ gap: space.md, paddingBottom: space.md }}>
        <View style={{ flexDirection: 'row', alignItems: 'center', paddingTop: space.md }}>
          <View style={{ flex: 1 }}>
            <Text variant="caption" tone="muted">
              {firstName ? t('Hello, {name}', { name: firstName }) : t('Welcome')}
            </Text>
            <Text variant="title" numberOfLines={1}>
              {business ?? t('Shop')}
            </Text>
          </View>
          <IconButton icon="basket-outline" label={t('Basket')} badge={inBasket} filled onPress={() => router.push('/basket')} />
        </View>
        <SearchBar
          value={search}
          onChangeText={(v) => {
            setSearch(v);
            setPage(1);
          }}
          placeholder={t('Search products')}
        />
        {(categories.data?.length ?? 0) > 0 ? (
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: space.sm }}>
            <Chip label={t('All')} selected={category === null} onPress={() => choose(null)} />
            {categories.data!.map((cat) => (
              <Chip key={cat.id} label={cat.name} selected={category === cat.slug} onPress={() => choose(cat.slug)} />
            ))}
          </ScrollView>
        ) : null}
      </View>
    ),
    [search, category, categories.data, inBasket, business, firstName, t],
  );

  const card = ({ item: p }: { item: ShopProduct }) => (
    <Pressable onPress={() => router.push(`/item/${p.slug}`)} style={({ pressed }) => [{ width: cardWidth, borderRadius: radius.lg, backgroundColor: c.surface, overflow: 'hidden', borderWidth: 1, borderColor: c.border, opacity: pressed ? 0.85 : 1 }, shadow(c, 1)]}>
      <View style={{ width: '100%', aspectRatio: 1, backgroundColor: c.surfaceAlt, alignItems: 'center', justifyContent: 'center' }}>
        {p.image_url ? <Image source={{ uri: p.image_url }} style={{ width: '100%', height: '100%' }} resizeMode="contain" /> : <Ionicons name="cube-outline" size={36} color={c.textFaint} />}
        {!p.in_stock ? (
          <View style={{ position: 'absolute', left: 8, top: 8, backgroundColor: c.dangerSoft, borderRadius: radius.pill, paddingHorizontal: 8, paddingVertical: 2 }}>
            <Text variant="tiny" tone="danger">
              {t('Out of stock')}
            </Text>
          </View>
        ) : null}
      </View>
      <View style={{ padding: space.md, gap: 6 }}>
        <Text variant="label" numberOfLines={2} style={{ minHeight: 34 }}>
          {p.name}
        </Text>
        <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
          <Text variant="bodyStrong" tone="primary" numeric numberOfLines={1} style={{ flex: 1 }}>
            {money(num(p.selling_price))}
          </Text>
          {p.in_stock ? (
            <Pressable onPress={() => quickAdd(p)} hitSlop={8} accessibilityLabel={t('Add {name}', { name: p.name })} style={({ pressed }) => ({ width: 34, height: 34, borderRadius: 17, backgroundColor: pressed ? c.primaryPressed : c.primary, alignItems: 'center', justifyContent: 'center' })}>
              <Ionicons name="add" size={22} color={c.onPrimary} />
            </Pressable>
          ) : null}
        </View>
        {p.unit ? (
          <Text variant="caption" tone="faint">
            {t('per {unit}', { unit: p.unit })}
          </Text>
        ) : null}
      </View>
    </Pressable>
  );

  const empty = list.loading ? (
    <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: space.md }}>
      {Array.from({ length: 6 }, (_, i) => (
        <Skeleton key={i} width={cardWidth} height={cardWidth + 90} round={radius.lg} />
      ))}
    </View>
  ) : list.error ? (
    <EmptyState icon={list.error.offline ? 'cloud-offline-outline' : 'alert-circle-outline'} tone={list.error.offline ? 'warning' : 'danger'} title={list.error.offline ? t('No internet') : t('Could not load the shop')} text={list.error.offline ? t('The shop needs internet. Connect and pull down to try again.') : list.error.message} action={{ title: t('Try again'), onPress: list.reload, icon: 'refresh' }} />
  ) : (
    <EmptyState icon="search-outline" title={t('No products found')} text={search ? t('Try another word.') : undefined} />
  );

  return (
    <SafeAreaView edges={['top']} style={{ flex: 1, backgroundColor: c.bg }}>
      <FlatList
        key={columns}
        data={list.error && page === 1 ? [] : items}
        keyExtractor={(p) => String(p.id)}
        numColumns={columns}
        renderItem={card}
        ListHeaderComponent={header}
        ListEmptyComponent={empty}
        columnWrapperStyle={{ gap: space.md }}
        contentContainerStyle={{ paddingHorizontal: space.lg, paddingBottom: space.xxxl, gap: space.md }}
        onEndReachedThreshold={0.4}
        onEndReached={() => {
          if (!list.loading && page < lastPage) setPage((n) => n + 1);
        }}
        ListFooterComponent={list.loading && page > 1 ? <Skeleton height={60} round={radius.lg} /> : undefined}
        refreshControl={
          <RefreshControl
            refreshing={false}
            onRefresh={() => {
              categories.reload();
              if (page === 1) list.reload();
              else setPage(1);
            }}
            tintColor={c.primary}
            colors={[c.primary]}
          />
        }
        keyboardShouldPersistTaps="handled"
      />
    </SafeAreaView>
  );
}
