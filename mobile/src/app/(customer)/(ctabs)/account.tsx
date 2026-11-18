import { View } from 'react-native';
import { router } from 'expo-router';
import Constants from 'expo-constants';
import { useSession } from '@/lib/session';
import { usePrefs } from '@/lib/prefs';
import { useLocale, useT } from '@/i18n';
import { sendTest } from '@/notifications';
import { Button, Card, Group, Header, IconButton, ListRow, Screen, Section, Segmented, Text, confirm, space, toast, useColors } from '@/ui';

type AlertChoice = 'both' | 'sound' | 'vibrate' | 'silent';

/** The customer's account: profile, password, how order alerts sound, language, sign out. */
export default function Account() {
  const t = useT();
  const c = useColors();
  const { user, logout } = useSession();
  const locale = useLocale((s) => s.locale);
  const orders = usePrefs((s) => s.notifications.categories.orders);
  const theme = usePrefs((s) => s.theme);
  const initials = (user?.name ?? '?').split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase();

  const choice: AlertChoice = !orders.enabled ? 'silent' : orders.sound && orders.vibrate ? 'both' : orders.sound ? 'sound' : orders.vibrate ? 'vibrate' : 'silent';
  const choose = async (v: AlertChoice) => {
    await usePrefs.getState().setAlert('orders', { enabled: true, voice: false, sound: v === 'both' || v === 'sound', vibrate: v === 'both' || v === 'vibrate' });
    toast.success(t('Order alerts updated.'));
  };
  const test = async () => {
    await sendTest('orders');
    toast.info(t('A test alert was sent to this phone.'));
  };
  const signOut = async () => {
    if (await confirm({ title: t('Sign out?'), message: t('Your basket stays on this phone.'), confirm: t('Sign out') })) await logout();
  };

  return (
    <Screen scroll>
      <Header title={t('Account')} right={<IconButton icon="log-out-outline" tone="danger" label={t('Sign out')} onPress={signOut} />} />
      <Card style={{ flexDirection: 'row', alignItems: 'center', gap: space.md }}>
        <View style={{ width: 52, height: 52, borderRadius: 26, backgroundColor: c.primary, alignItems: 'center', justifyContent: 'center' }}>
          <Text variant="heading" style={{ color: '#fff' }}>
            {initials}
          </Text>
        </View>
        <View style={{ flex: 1, gap: 2 }}>
          <Text variant="heading">{user?.name}</Text>
          <Text variant="caption" tone="muted">
            {[user?.phone, user?.email].filter(Boolean).join(' · ')}
          </Text>
        </View>
      </Card>

      <Section title={t('My account')}>
        <Group>
          <ListRow icon="person-outline" title={t('Edit my details')} subtitle={t('Name, phone, email, address')} onPress={() => router.push('/profile')} />
          <ListRow icon="key-outline" iconTone="warning" title={t('Change password')} onPress={() => router.push('/password')} />
          <ListRow icon="receipt-outline" iconTone="success" title={t('My orders')} onPress={() => router.navigate('/my-orders')} />
        </Group>
      </Section>

      <Section title={t('Order alerts')}>
        <Card style={{ gap: space.md }}>
          <Text variant="caption" tone="muted">
            {t('How this phone tells you that an order was confirmed, is ready or is on the way.')}
          </Text>
          <Segmented
            value={choice}
            onChange={choose}
            options={[
              { value: 'both', label: t('Both') },
              { value: 'sound', label: t('Sound') },
              { value: 'vibrate', label: t('Vibrate') },
              { value: 'silent', label: t('Silent') },
            ]}
          />
          <Button title={t('Send a test alert')} variant="secondary" size="sm" icon="notifications-outline" onPress={test} />
        </Card>
      </Section>

      <Section title={t('This phone')}>
        <Card style={{ gap: space.md }}>
          <Text variant="label">{t('Language')}</Text>
          <Segmented value={locale} onChange={(v) => usePrefs.getState().update({ locale: v })} options={[{ value: 'sw', label: 'Kiswahili' }, { value: 'en', label: 'English' }]} />
          <Text variant="label">{t('Theme')}</Text>
          <Segmented value={theme} onChange={(v) => usePrefs.getState().update({ theme: v })} options={[{ value: 'system', label: t('Auto') }, { value: 'light', label: t('Light') }, { value: 'dark', label: t('Dark') }]} />
        </Card>
      </Section>

      <Section>
        <Group>
          <ListRow icon="log-out-outline" iconTone="danger" title={t('Sign out')} danger onPress={signOut} chevron={false} />
        </Group>
      </Section>

      <Text variant="caption" tone="faint" center style={{ marginTop: space.xl }}>
        © {new Date().getFullYear()} SHEZONI Technologies Ltd · PatoPro v{Constants.expoConfig?.version ?? '1.0'}
      </Text>
    </Screen>
  );
}
