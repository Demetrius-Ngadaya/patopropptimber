import { useState } from 'react';
import { FlatList, View } from 'react-native';
import { router } from 'expo-router';
import { useLive } from '@/lib/useLive';
import { ago } from '@/lib/format';
import { listNotifications } from '@/db/repo';
import { markAllNotificationsRead, markNotificationRead } from '@/db/commands';
import { routeFor } from '@/notifications/links';
import { updateBadge } from '@/notifications';
import { requestSync, syncSoon, useSync } from '@/sync/manager';
import { useT } from '@/i18n';
import { Button, EmptyState, IconButton, ListRow, Screen, Segmented, SkeletonList, Text, space, useColors } from '@/ui';
import { CATEGORY_META, ScreenHeader } from '@/features/common';


export default function Notifications() {
  const t = useT();
  const c = useColors();
  const running = useSync((s) => s.running);
  const [filter, setFilter] = useState<'all' | 'unread'>('all');
  const list = useLive(() => listNotifications(filter === 'unread', 200), ['notifications'], [filter]);

  return (
    <Screen padded={false} edges={[]}>
      <ScreenHeader
        title={t('Notifications')}
        right={<IconButton icon="settings-outline" label={t('Notification settings')} onPress={() => router.push('/settings/notifications')} />}
      />
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: space.sm, paddingHorizontal: space.lg, paddingBottom: space.sm }}>
        <View style={{ flex: 1 }}>
          <Segmented value={filter} onChange={setFilter} options={[{ value: 'all', label: t('All') }, { value: 'unread', label: t('Unread') }]} />
        </View>
        <Button
          title={t('Read all')}
          size="sm"
          variant="ghost"
          icon="checkmark-done"
          onPress={async () => {
            await markAllNotificationsRead();
            await updateBadge();
            syncSoon();
          }}
        />
      </View>
      {list.loading ? (
        <SkeletonList />
      ) : (
        <FlatList
          data={list.data}
          keyExtractor={(n) => String(n.id)}
          refreshing={running}
          onRefresh={() => requestSync('manual', { only: ['notifications'] })}
          ListEmptyComponent={<EmptyState icon="notifications-off-outline" title={filter === 'unread' ? t('No unread notifications') : t('No notifications yet')} tone="success" />}
          renderItem={({ item: n }) => {
            const meta = CATEGORY_META[n.category] ?? CATEGORY_META.general;
            return (
              <ListRow
                icon={meta.icon}
                iconTone={n.is_read ? 'neutral' : meta.tone}
                title={n.title}
                subtitle={n.body}
                numberOfLines={2}
                meta={
                  <Text variant="caption" tone="faint">
                    {ago(n.created_at, t)}
                  </Text>
                }
                right={!n.is_read ? <View style={{ width: 9, height: 9, borderRadius: 5, backgroundColor: c.primary }} /> : undefined}
                onPress={async () => {
                  await markNotificationRead(n.id);
                  updateBadge();
                  syncSoon();
                  const route = routeFor({ url: n.url, notification_id: n.id, category: n.category });
                  router.push((route.startsWith('/notifications') ? `/notifications/${n.id}` : route) as never);
                }}
              />
            );
          }}
        />
      )}
    </Screen>
  );
}
