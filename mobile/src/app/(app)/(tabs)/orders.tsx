import { useState } from 'react';
import { FlatList, View } from 'react-native';
import { router } from 'expo-router';
import { useDebounced, useLive } from '@/lib/useLive';
import { ago, money } from '@/lib/format';
import { listOrders } from '@/db/repo';
import { requestSync, useSync } from '@/sync/manager';
import { useT } from '@/i18n';
import { Badge, EmptyState, Header, ListRow, Screen, SearchBar, Segmented, SkeletonList, Text, space } from '@/ui';
import { ORDER_STATUS, OfflineBanner, SyncPill } from '@/features/common';

type Filter = 'open' | 'completed' | 'cancelled' | 'all';

/** Online-shop and phone orders for this branch, newest first. */
export default function Orders() {
  const t = useT();
  const [filter, setFilter] = useState<Filter>('open');
  const [search, setSearch] = useState('');
  const term = useDebounced(search, 200);
  const running = useSync((s) => s.running);
  const orders = useLive(() => listOrders(filter, term), ['orders'], [filter, term]);

  return (
    <Screen padded={false}>
      <View style={{ paddingHorizontal: space.lg, gap: space.md, paddingBottom: space.md }}>
        <Header title={t('Orders')} subtitle={t('New orders alert you by voice and vibration')} right={<SyncPill />} />
        <SearchBar value={search} onChangeText={setSearch} placeholder={t('Order number, customer or phone')} />
        <Segmented
          value={filter}
          onChange={setFilter}
          options={[
            { value: 'open', label: t('Open') },
            { value: 'completed', label: t('Done') },
            { value: 'cancelled', label: t('Cancelled') },
            { value: 'all', label: t('All') },
          ]}
        />
      </View>
      <OfflineBanner />
      {orders.loading ? (
        <SkeletonList />
      ) : (
        <FlatList
          data={orders.data}
          keyExtractor={(o) => String(o.id)}
          refreshing={running}
          onRefresh={() => requestSync('manual', { only: ['orders', 'notifications'] })}
          contentContainerStyle={{ paddingBottom: space.xxxl }}
          ListEmptyComponent={
            <EmptyState
              icon={filter === 'open' ? 'checkmark-done-circle-outline' : 'receipt-outline'}
              title={filter === 'open' ? t('No open orders') : t('No orders here')}
              text={filter === 'open' ? t('New online orders appear here, with a voice alert.') : undefined}
              tone={filter === 'open' ? 'success' : 'primary'}
            />
          }
          renderItem={({ item: o }) => {
            const status = o.pending_status ?? o.status;
            const s = ORDER_STATUS[status];
            return (
              <ListRow
                icon={s.icon}
                iconTone={s.tone}
                title={`${o.number} · ${o.customer_name ?? t('Customer')}`}
                subtitle={[t(s.label), o.delivery_method === 'delivery' ? t('Delivery') : t('Pickup'), ago(o.created_at, t)].join(' · ')}
                meta={o.pending_status ? <Badge label={t('Waiting to sync')} tone="warning" icon="cloud-upload" /> : null}
                right={
                  <View style={{ alignItems: 'flex-end', gap: 2 }}>
                    <Text variant="bodyStrong" numeric>
                      {money(o.total, false)}
                    </Text>
                    {o.paid_online > 0 ? <Badge label={t('Paid online')} tone="success" /> : null}
                  </View>
                }
                onPress={() => router.push(`/orders/${o.id}`)}
              />
            );
          }}
        />
      )}
    </Screen>
  );
}
