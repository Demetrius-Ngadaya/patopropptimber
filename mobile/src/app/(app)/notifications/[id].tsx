import { useEffect } from 'react';
import { ScrollView, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { useLive } from '@/lib/useLive';
import { dateTime } from '@/lib/format';
import { getDb } from '@/db/database';
import { markNotificationRead } from '@/db/commands';
import { routeFor } from '@/notifications/links';
import { speak, voiceText } from '@/notifications/voice';
import { syncSoon } from '@/sync/manager';
import { useT } from '@/i18n';
import { Badge, Button, Card, EmptyState, IconTile, Screen, Text, space } from '@/ui';
import { CATEGORY_META, ScreenHeader } from '@/features/common';
import type { AppNotification } from '@/lib/types';

/** One notification (opened from the notification bar when it is not about an order / product / sale). */
export default function NotificationScreen() {
  const t = useT();
  const { id } = useLocalSearchParams<{ id: string }>();
  const n = useLive(
    async () => (await getDb()).getFirstAsync<Omit<AppNotification, 'is_read'> & { is_read: number }>('SELECT * FROM notifications WHERE id = ?', Number(id)),
    ['notifications'],
    [id],
  );

  useEffect(() => {
    if (id) markNotificationRead(Number(id)).then(syncSoon).catch(() => undefined);
  }, [id]);

  const item = n.data;
  const meta = item ? CATEGORY_META[item.category] ?? CATEGORY_META.general : CATEGORY_META.general;
  const target = item ? routeFor({ url: item.url, notification_id: item.id, category: item.category }) : null;

  return (
    <Screen padded={false} edges={[]}>
      <ScreenHeader title={t('Notification')} />
      {!item ? (
        n.loading ? null : <EmptyState icon="notifications-outline" title={t('This notification is not on the phone yet')} text={t('It arrives with the next sync.')} />
      ) : (
        <ScrollView contentContainerStyle={{ padding: space.lg, gap: space.lg }}>
          <Card style={{ gap: space.md }}>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: space.md }}>
              <IconTile name={meta.icon} tone={meta.tone} size={48} />
              <View style={{ flex: 1, gap: 4 }}>
                <Badge label={t(meta.label)} tone={meta.tone} />
                <Text variant="caption" tone="faint">
                  {dateTime(item.created_at)}
                </Text>
              </View>
            </View>
            <Text variant="title">{item.title}</Text>
            {item.body ? <Text>{item.body}</Text> : null}
          </Card>
          <Button title={t('Read it out loud')} icon="volume-high-outline" variant="secondary" onPress={() => speak(voiceText(item.title, item.body))} />
          {target && !target.startsWith('/notifications') ? <Button title={t('Open')} icon="open-outline" onPress={() => router.replace(target as never)} /> : null}
        </ScrollView>
      )}
    </Screen>
  );
}
