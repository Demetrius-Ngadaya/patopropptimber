import { FlatList, RefreshControl, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useCallback } from 'react';
import { router, useFocusEffect } from 'expo-router';
import { dateTime, money } from '@/lib/format';
import { useT } from '@/i18n';
import { Badge, EmptyState, Header, ListRow, SkeletonList, space, useColors } from '@/ui';
import { CUSTOMER_STATUS, num, shopApi, useRemote } from '@/features/shop/data';
import { useOrderAlerts } from '@/features/shop/alerts';

/** The customer's orders, newest first, with where each one is. */
export default function MyOrders() {
  const t = useT();
  const c = useColors();
  const version = useOrderAlerts((s) => s.version);
  const orders = useRemote(() => shopApi.orders(1), [version]);
  const { reload } = orders;
  useFocusEffect(useCallback(() => void reload(), [reload]));

  return (
    <SafeAreaView edges={['top']} style={{ flex: 1, backgroundColor: c.bg }}>
      <FlatList
        data={orders.data ?? []}
        keyExtractor={(o) => o.number}
        contentContainerStyle={{ paddingHorizontal: space.lg, paddingBottom: space.xxxl, gap: space.sm }}
        ListHeaderComponent={<Header title={t('My orders')} subtitle={t('You get an alert on this phone when an order moves on.')} />}
        renderItem={({ item: o }) => {
          const s = CUSTOMER_STATUS[o.status] ?? CUSTOMER_STATUS.pending;
          return (
            <View style={{ backgroundColor: c.surface, borderRadius: 14, borderWidth: 1, borderColor: c.border, overflow: 'hidden' }}>
              <ListRow
                icon={s.icon}
                iconTone={s.tone}
                title={o.number}
                subtitle={`${dateTime(o.created_at)} · ${o.delivery_method === 'delivery' ? t('Delivery') : t('Collect at {branch}', { branch: o.branch?.name ?? t('the shop') })}`}
                meta={money(num(o.total))}
                right={<Badge label={t(s.label)} tone={s.tone} />}
                onPress={() => router.push(`/my-orders/${encodeURIComponent(o.number)}`)}
              />
            </View>
          );
        }}
        ListEmptyComponent={
          orders.loading ? (
            <SkeletonList rows={5} />
          ) : orders.error ? (
            <EmptyState icon={orders.error.offline ? 'cloud-offline-outline' : 'alert-circle-outline'} tone="warning" title={orders.error.offline ? t('No internet') : t('Could not load your orders')} text={orders.error.offline ? t('Connect to the internet and pull down to try again.') : orders.error.message} action={{ title: t('Try again'), onPress: reload, icon: 'refresh' }} />
          ) : (
            <EmptyState icon="receipt-outline" title={t('No orders yet')} text={t('Your orders appear here after you place them.')} action={{ title: t('Go to the shop'), onPress: () => router.navigate('/shop'), icon: 'storefront-outline' }} />
          )
        }
        refreshControl={<RefreshControl refreshing={false} onRefresh={reload} tintColor={c.primary} colors={[c.primary]} />}
      />
    </SafeAreaView>
  );
}
