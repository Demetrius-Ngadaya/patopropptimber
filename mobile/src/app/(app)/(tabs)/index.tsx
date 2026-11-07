import { useState } from 'react';
import { Pressable, View } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useSession, useCan } from '@/lib/session';
import { useLive } from '@/lib/useLive';
import { money, ago, time } from '@/lib/format';
import { countOpenOrders, listNotifications, listOrders, stockSummary, todaySummary, unreadCount } from '@/db/repo';
import { requestSync, useSync } from '@/sync/manager';
import { useT } from '@/i18n';
import { Badge, Card, EmptyState, Group, IconButton, IconTile, ListRow, Screen, Section, Skeleton, StatTile, Text, radius, space, useColors, type IconName } from '@/ui';
import { BranchSwitcher, ORDER_STATUS, OfflineBanner, SyncPill, useBranchName, useCountUp } from '@/features/common';

function greeting(t: (k: string) => string) {
  const h = new Date().getHours();
  return h < 12 ? t('Good morning') : h < 17 ? t('Good afternoon') : t('Good evening');
}

export default function Home() {
  const t = useT();
  const c = useColors();
  const insets = useSafeAreaInsets();
  const user = useSession((s) => s.user);
  const settings = useSession((s) => s.settings);
  const branch = useBranchName();
  const canSell = useCan('pos.access');
  const canOrders = useCan('orders.view');
  const canAllSales = useCan('sales.view_all_users');
  const running = useSync((s) => s.running);
  const [branchOpen, setBranchOpen] = useState(false);

  const today = useLive(() => todaySummary(canAllSales ? null : user?.id), ['sales'], [user?.id, canAllSales]);
  const stock = useLive(stockSummary, ['products', 'sales'], []);
  const openOrders = useLive(countOpenOrders, ['orders'], []);
  const orders = useLive(() => listOrders('open'), ['orders'], []);
  const unread = useLive(unreadCount, ['notifications'], []).data ?? 0;
  const notes = useLive(() => listNotifications(false, 3), ['notifications'], []);
  const total = useCountUp(today.data?.total ?? 0);

  const actions: { icon: IconName; label: string; onPress: () => void; show: boolean; tone: 'primary' | 'success' | 'warning' | 'info' }[] = [
    { icon: 'cart', label: t('New sale'), onPress: () => router.push('/sell'), show: canSell, tone: 'primary' },
    { icon: 'scan', label: t('Scan'), onPress: () => router.push('/scan'), show: canSell, tone: 'info' },
    { icon: 'people', label: t('Customers'), onPress: () => router.push('/customers'), show: true, tone: 'success' },
    { icon: 'time', label: t('Sales'), onPress: () => router.push('/sales'), show: true, tone: 'warning' },
  ];

  const max = Math.max(1, ...(today.data?.hourly ?? [0]));
  const hours = (today.data?.hourly ?? []).slice(6, 23);

  return (
    <Screen scroll padded={false} edges={[]} refreshing={running} onRefresh={() => requestSync('manual')}>
      {/* Hero */}
      <View style={{ backgroundColor: c.heroFrom, paddingTop: insets.top + space.sm, paddingHorizontal: space.lg, paddingBottom: 72, borderBottomLeftRadius: 28, borderBottomRightRadius: 28 }}>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: space.sm }}>
          <Pressable onPress={() => setBranchOpen(true)} style={{ flex: 1 }}>
            <Text variant="caption" style={{ color: 'rgba(255,255,255,0.75)' }}>
              {greeting(t)}, {user?.name?.split(' ')[0]}
            </Text>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}>
              <Text variant="heading" style={{ color: '#fff' }} numberOfLines={1}>
                {branch || settings.business_name || 'PatoPro'}
              </Text>
              {(user?.all_branches && (user.branches?.length ?? 0) > 1) ? <Ionicons name="chevron-down" size={16} color="#fff" /> : null}
            </View>
          </Pressable>
          <SyncPill onDark />
          <IconButton icon="notifications-outline" label={t('Notifications')} tone="onPrimary" filled badge={unread} onPress={() => router.push('/notifications')} />
        </View>
      </View>

      <OfflineBanner />

      <View style={{ paddingHorizontal: space.lg, marginTop: -56 }}>
        {/* Today */}
        <Card elevated style={{ gap: space.md }}>
          <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
            <Text variant="label" tone="muted">
              {canAllSales ? t("Today's sales") : t('My sales today')}
            </Text>
            {today.data?.unsynced ? <Badge label={t('{n} not synced', { n: today.data.unsynced })} tone="warning" icon="cloud-upload" /> : <Badge label={t('Live')} tone="success" icon="pulse" />}
          </View>
          {today.loading ? (
            <Skeleton width="60%" height={34} />
          ) : (
            <Text variant="display" numeric>
              {money(total)}
            </Text>
          )}
          <View style={{ flexDirection: 'row', alignItems: 'flex-end', height: 44, gap: 3 }}>
            {hours.map((v, i) => (
              <View key={i} style={{ flex: 1, height: `${Math.max(6, (v / max) * 100)}%`, borderRadius: 3, backgroundColor: v ? c.primary : c.surfaceAlt, opacity: v ? 0.35 + 0.65 * (v / max) : 1 }} />
            ))}
          </View>
          <View style={{ flexDirection: 'row', gap: space.lg }}>
            <Mini label={t('Sales')} value={String(today.data?.count ?? 0)} />
            <Mini label={t('Cash')} value={money(today.data?.cash ?? 0, false)} />
            <Mini label={t('On credit')} value={money(today.data?.credit ?? 0, false)} />
          </View>
        </Card>

        {/* Quick actions */}
        <View style={{ flexDirection: 'row', gap: space.sm, marginTop: space.lg }}>
          {actions.filter((a) => a.show).map((a) => (
            <Pressable key={a.label} onPress={a.onPress} style={({ pressed }) => ({ flex: 1, alignItems: 'center', gap: 6, paddingVertical: space.md, borderRadius: radius.lg, backgroundColor: pressed ? c.surfacePressed : c.surface, borderWidth: 1, borderColor: c.border })}>
              <IconTile name={a.icon} tone={a.tone} size={42} />
              <Text variant="caption" style={{ fontWeight: '700', textAlign: 'center' }} numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.8}>
                {a.label}
              </Text>
            </Pressable>
          ))}
        </View>

        {/* Stats */}
        <View style={{ flexDirection: 'row', gap: space.sm, marginTop: space.lg }}>
          {canOrders ? <StatTile icon="receipt" label={t('Open orders')} value={String(openOrders.data ?? 0)} tone="primary" onPress={() => router.push('/orders')} /> : null}
          <StatTile icon="trending-down" label={t('Low stock')} value={String(stock.data?.low ?? 0)} tone="warning" onPress={() => router.push({ pathname: '/products', params: { filter: 'low' } })} />
          <StatTile icon="alert-circle" label={t('Out of stock')} value={String(stock.data?.out ?? 0)} tone="danger" onPress={() => router.push({ pathname: '/products', params: { filter: 'out' } })} />
        </View>

        {canOrders ? (
          <Section title={t('Orders waiting')} action={{ label: t('See all'), onPress: () => router.push('/orders') }}>
            {orders.data && orders.data.length === 0 ? (
              <Card>
                <EmptyState icon="checkmark-done" title={t('All caught up')} text={t('New online orders appear here, with a voice alert.')} tone="success" />
              </Card>
            ) : (
              <Group>
                {(orders.data ?? []).slice(0, 4).map((o) => {
                  const s = ORDER_STATUS[o.pending_status ?? o.status];
                  return (
                    <ListRow
                      key={o.id}
                      icon={s.icon}
                      iconTone={s.tone}
                      title={`${o.number} · ${o.customer_name ?? t('Customer')}`}
                      subtitle={`${t(s.label)} · ${ago(o.created_at, t)}`}
                      right={<Text variant="bodyStrong" numeric>{money(o.total, false)}</Text>}
                      onPress={() => router.push(`/orders/${o.id}`)}
                    />
                  );
                })}
              </Group>
            )}
          </Section>
        ) : null}

        {notes.data && notes.data.length ? (
          <Section title={t('Latest notifications')} action={{ label: t('See all'), onPress: () => router.push('/notifications') }}>
            <Group>
              {notes.data.map((n) => (
                <ListRow
                  key={n.id}
                  icon={n.is_read ? 'notifications-outline' : 'notifications'}
                  iconTone={n.is_read ? 'neutral' : 'primary'}
                  title={n.title}
                  subtitle={`${time(n.created_at)} · ${n.body ?? ''}`}
                  onPress={() => router.push(`/notifications/${n.id}`)}
                />
              ))}
            </Group>
          </Section>
        ) : null}
      </View>
      <BranchSwitcher visible={branchOpen} onClose={() => setBranchOpen(false)} />
    </Screen>
  );
}

function Mini({ label, value }: { label: string; value: string }) {
  return (
    <View style={{ flex: 1 }}>
      <Text variant="caption" tone="muted">
        {label}
      </Text>
      <Text variant="bodyStrong" numeric numberOfLines={1}>
        {value}
      </Text>
    </View>
  );
}
