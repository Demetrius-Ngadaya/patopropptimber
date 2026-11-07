import { useState } from 'react';
import { FlatList, ScrollView, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { useDebounced, useLive } from '@/lib/useLive';
import { money, qty } from '@/lib/format';
import { listProducts, stockSummary } from '@/db/repo';
import { useT } from '@/i18n';
import { Badge, Chip, EmptyState, Header, IconButton, ListRow, Screen, SearchBar, SkeletonList, Text, space } from '@/ui';
import { OfflineBanner, SyncPill } from '@/features/common';

type Filter = 'all' | 'low' | 'out';

/** Products and stock of this branch (stock minus sales not yet synced). */
export default function Products() {
  const t = useT();
  const params = useLocalSearchParams<{ filter?: Filter }>();
  const [filter, setFilter] = useState<Filter>(params.filter ?? 'all');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const term = useDebounced(search, 200);
  const [linked, setLinked] = useState(params.filter);
  if (params.filter !== linked) {
    setLinked(params.filter);
    if (params.filter) setFilter(params.filter);
  }

  const summary = useLive(stockSummary, ['products', 'sales'], []);
  const products = useLive(
    async () => {
      const rows = await listProducts({ search: term, lowStockOnly: filter !== 'all', limit: page * 60 });
      return filter === 'out' ? rows.filter((p) => p.stock <= 0) : filter === 'low' ? rows.filter((p) => p.stock > 0) : rows;
    },
    ['products', 'sales'],
    [term, filter, page],
  );

  return (
    <Screen padded={false}>
      <View style={{ paddingHorizontal: space.lg, gap: space.md }}>
        <Header
          title={t('Stock')}
          subtitle={summary.data ? (summary.data.products === 1 ? t('1 product') : t('{n} products', { n: summary.data.products })) : undefined}
          right={
            <>
              <SyncPill />
              <IconButton icon="barcode-outline" label={t('Scan to look up')} onPress={() => router.push({ pathname: '/scan', params: { mode: 'lookup' } })} />
            </>
          }
        />
        <SearchBar value={search} onChangeText={(s) => { setSearch(s); setPage(1); }} placeholder={t('Search name, code or barcode')} />
      </View>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ flexGrow: 0, flexShrink: 0 }} contentContainerStyle={{ gap: space.sm, paddingHorizontal: space.lg, paddingVertical: space.md }}>
        <Chip label={t('All')} selected={filter === 'all'} onPress={() => setFilter('all')} />
        <Chip label={t('Low stock')} icon="trending-down" count={summary.data?.low} selected={filter === 'low'} onPress={() => setFilter('low')} />
        <Chip label={t('Out of stock')} icon="alert-circle" count={summary.data?.out} selected={filter === 'out'} onPress={() => setFilter('out')} />
      </ScrollView>
      <OfflineBanner />
      {products.loading ? (
        <SkeletonList />
      ) : (
        <FlatList
          data={products.data}
          keyExtractor={(p) => String(p.id)}
          keyboardDismissMode="on-drag"
          onEndReached={() => products.data && products.data.length >= page * 60 - 5 && setPage((p) => p + 1)}
          onEndReachedThreshold={0.4}
          contentContainerStyle={{ paddingBottom: space.xxxl }}
          ListEmptyComponent={<EmptyState icon={filter === 'all' ? 'cube-outline' : 'checkmark-circle-outline'} title={filter === 'all' ? t('No products found') : t('Nothing here: stock is fine')} tone={filter === 'all' ? 'primary' : 'success'} />}
          renderItem={({ item: p }) => {
            const out = !p.is_service && p.stock <= 0;
            const low = !p.is_service && !out && p.stock <= (p.reorder_level ?? 0);
            return (
              <ListRow
                icon={p.is_service ? 'construct-outline' : 'cube-outline'}
                iconTone={out ? 'danger' : low ? 'warning' : 'neutral'}
                title={p.name}
                subtitle={[p.sku, p.barcode].filter(Boolean).join(' · ') || null}
                right={
                  <View style={{ alignItems: 'flex-end', gap: 3 }}>
                    <Text variant="bodyStrong" numeric>
                      {money(p.selling_price, false)}
                    </Text>
                    {p.is_service ? null : <Badge label={`${qty(p.stock)} ${p.unit ?? ''}`.trim()} tone={out ? 'danger' : low ? 'warning' : 'success'} />}
                  </View>
                }
                onPress={() => router.push(`/products/${p.id}`)}
              />
            );
          }}
        />
      )}
    </Screen>
  );
}
