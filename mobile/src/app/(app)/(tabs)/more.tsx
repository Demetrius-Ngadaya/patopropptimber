import { useState } from 'react';
import { View } from 'react-native';
import { router } from 'expo-router';
import Constants from 'expo-constants';
import { useSession } from '@/lib/session';
import { useLive } from '@/lib/useLive';
import { unreadCount } from '@/db/repo';
import { useSync } from '@/sync/manager';
import { useT } from '@/i18n';
import { Badge, Card, Group, Header, ListRow, Screen, Section, Text, confirm, space, useColors } from '@/ui';
import { BranchSwitcher, useBranchName } from '@/features/common';

export default function More() {
  const t = useT();
  const c = useColors();
  const { user, logout } = useSession();
  const branch = useBranchName();
  const { waiting, attention } = useSync();
  const unread = useLive(unreadCount, ['notifications'], []).data ?? 0;
  const [branchOpen, setBranchOpen] = useState(false);
  const initials = (user?.name ?? '?').split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase();

  const signOut = async () => {
    const pending = waiting + attention;
    const ok = await confirm({
      title: t('Sign out?'),
      message: pending ? t('{n} changes are not on the server yet. They stay on this phone and are sent when you sign in again.', { n: pending }) : t('You can sign in again at any time.'),
      confirm: t('Sign out'),
      danger: !!pending,
    });
    if (ok) await logout();
  };

  return (
    <Screen scroll>
      <Header title={t('More')} />
      <Card style={{ flexDirection: 'row', alignItems: 'center', gap: space.md }}>
        <View style={{ width: 52, height: 52, borderRadius: 26, backgroundColor: c.primary, alignItems: 'center', justifyContent: 'center' }}>
          <Text variant="heading" style={{ color: '#fff' }}>
            {initials}
          </Text>
        </View>
        <View style={{ flex: 1, gap: 2 }}>
          <Text variant="heading">{user?.name}</Text>
          <Text variant="caption" tone="muted">
            {[user?.role?.name, user?.username].filter(Boolean).join(' · ')}
          </Text>
        </View>
      </Card>

      <Section title={t('Work')}>
        <Group>
          <ListRow icon="storefront-outline" title={t('Branch')} subtitle={branch} onPress={() => setBranchOpen(true)} />
          <ListRow icon="people-outline" iconTone="success" title={t('Customers')} subtitle={t('Add, edit, receive debt payments')} onPress={() => router.push('/customers')} />
          <ListRow icon="time-outline" iconTone="warning" title={t('Sales history')} onPress={() => router.push('/sales')} />
          <ListRow icon="notifications-outline" iconTone="info" title={t('Notifications')} right={unread ? <Badge label={String(unread)} tone="danger" /> : undefined} onPress={() => router.push('/notifications')} />
        </Group>
      </Section>

      <Section title={t('This phone')}>
        <Group>
          <ListRow
            icon="sync-outline"
            title={t('Sync centre')}
            subtitle={t('Offline changes, manual sync, history')}
            right={attention ? <Badge label={t('{n} to fix', { n: attention })} tone="danger" /> : waiting ? <Badge label={t('{n} waiting', { n: waiting })} tone="warning" /> : <Badge label={t('Synced')} tone="success" />}
            onPress={() => router.push('/sync')}
          />
          <ListRow icon="volume-high-outline" iconTone="success" title={t('Notification sounds & voice')} subtitle={t('Voice, sound or vibration for each kind')} onPress={() => router.push('/settings/notifications')} />
          <ListRow icon="settings-outline" iconTone="neutral" title={t('Settings')} subtitle={t('Language, theme, app lock, sync')} onPress={() => router.push('/settings')} />
        </Group>
      </Section>

      <Section>
        <Group>
          <ListRow icon="log-out-outline" iconTone="danger" title={t('Sign out')} danger onPress={signOut} chevron={false} />
        </Group>
      </Section>

      <Text variant="caption" tone="faint" center style={{ marginTop: space.xl }}>
        © {new Date().getFullYear()} SHEZONI Technologies Ltd · PatoPro v{Constants.expoConfig?.version ?? '1.0'}
      </Text>
      <BranchSwitcher visible={branchOpen} onClose={() => setBranchOpen(false)} />
    </Screen>
  );
}
