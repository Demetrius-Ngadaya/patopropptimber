import { useEffect, useState } from 'react';
import { Platform, ScrollView, View } from 'react-native';
import { router } from 'expo-router';
import Constants from 'expo-constants';
import * as Application from 'expo-application';
import { usePrefs } from '@/lib/prefs';
import { useSession } from '@/lib/session';
import { secure } from '@/lib/secure';
import { runtime } from '@/lib/runtime';
import { biometricAvailable } from '@/lib/lock';
import { wipeLocalData } from '@/db/database';
import { requestSync, useSync } from '@/sync/manager';
import { useT } from '@/i18n';
import { Button, Card, Group, Input, ListRow, Screen, Section, Segmented, Sheet, SwitchRow, Text, confirm, space, toast } from '@/ui';
import { ScreenHeader } from '@/features/common';
import { installedBuild, openDownload, useAppUpdate } from '@/features/AppUpdate';

export default function Settings() {
  const t = useT();
  const prefs = usePrefs();
  const apiUrl = useSession((s) => s.apiUrl);
  const waiting = useSync((s) => s.waiting + s.attention);
  const [bio, setBio] = useState(false);
  const [pinOpen, setPinOpen] = useState(false);
  const [pin1, setPin1] = useState('');
  const [pin2, setPin2] = useState('');

  useEffect(() => {
    biometricAvailable().then(setBio);
  }, []);

  const savePin = async () => {
    if (!/^\d{4,6}$/.test(pin1)) return toast.warning(t('The PIN must be 4 to 6 digits.'));
    if (pin1 !== pin2) return toast.warning(t('The two PINs are different.'));
    await secure.setPin(pin1);
    await prefs.update({ security: { pin: true } });
    setPinOpen(false);
    setPin1('');
    setPin2('');
    toast.success(t('App PIN saved.'));
  };

  const offered = useAppUpdate((u) => u.offered);
  const checking = useAppUpdate((u) => u.checking);
  const updateLabel = checking ? t('Checking…') : offered ? t('Version {version} is ready — tap to download', { version: offered.version }) : t('Tap to check the website for a newer version');
  const checkUpdate = async () => {
    if (offered) return openDownload(offered.download_url);
    const before = useAppUpdate.getState().checkedAt;
    const found = await useAppUpdate.getState().check({ force: true });
    if (useAppUpdate.getState().checkedAt === before) toast.warning(t('Could not reach the server. Check the internet and try again.'));
    else if (!found) toast.success(t('You have the latest version.'));
  };

  const resetData = async () => {
    if (waiting) return toast.warning(t('Send or fix the waiting changes first (Sync centre).'));
    const ok = await confirm({ title: t('Download everything again?'), message: t('The phone\'s copy is erased and downloaded fresh from the server. Use it if something looks wrong.'), confirm: t('Download again'), danger: true });
    if (!ok) return;
    await wipeLocalData(false);
    await requestSync('manual', { force: true });
    toast.success(t('Fresh copy downloaded.'));
  };

  return (
    <Screen padded={false} edges={[]}>
      <ScreenHeader title={t('Settings')} />
      <ScrollView contentContainerStyle={{ padding: space.lg, paddingBottom: space.xxxl }}>
        <Section title={t('Appearance')} style={{ marginTop: 0 }}>
          <Card style={{ gap: space.md }}>
            <Text variant="label" tone="muted">
              {t('Language')}
            </Text>
            <Segmented value={prefs.locale ?? 'auto'} onChange={(v) => prefs.update({ locale: v === 'auto' ? null : v })} options={[{ value: 'auto', label: t('Account') }, { value: 'sw', label: 'Kiswahili' }, { value: 'en', label: 'English' }]} />
            <Text variant="label" tone="muted">
              {t('Theme')}
            </Text>
            <Segmented value={prefs.theme} onChange={(v) => prefs.update({ theme: v })} options={[{ value: 'system', label: t('Phone') }, { value: 'light', label: t('Light') }, { value: 'dark', label: t('Dark') }]} />
          </Card>
        </Section>

        <Section title={t('Notifications and sync')}>
          <Group>
            <ListRow icon="volume-high-outline" iconTone="success" title={t('Notification sounds & voice')} onPress={() => router.push('/settings/notifications')} />
            <ListRow icon="sync-outline" title={t('Sync centre')} onPress={() => router.push('/sync')} />
          </Group>
        </Section>

        <Section title={t('App lock')}>
          <Card style={{ paddingVertical: 0 }}>
            <SwitchRow icon="finger-print" title={t('Fingerprint / face')} subtitle={bio ? t('Ask before opening the app') : t('Not set up on this phone')} value={prefs.security.biometric} disabled={!bio} onChange={(v) => prefs.update({ security: { biometric: v } })} />
            <SwitchRow
              icon="keypad-outline"
              title={t('App PIN')}
              subtitle={t('4 to 6 digits, for when the fingerprint does not work')}
              value={prefs.security.pin}
              onChange={async (v) => {
                if (v) setPinOpen(true);
                else {
                  await secure.setPin(null);
                  await prefs.update({ security: { pin: false } });
                }
              }}
            />
            {prefs.security.biometric || prefs.security.pin ? (
              <View style={{ paddingBottom: space.lg, gap: space.sm }}>
                <Text variant="label" tone="muted">
                  {t('Lock after the app is left for')}
                </Text>
                <Segmented
                  value={String(prefs.security.lockAfterMinutes)}
                  onChange={(v) => prefs.update({ security: { lockAfterMinutes: Number(v) } })}
                  options={[{ value: '0', label: t('Always') }, { value: '1', label: '1 min' }, { value: '5', label: '5 min' }, { value: '15', label: '15 min' }]}
                />
              </View>
            ) : null}
          </Card>
        </Section>

        <Section title={t('Till')}>
          <Card style={{ paddingVertical: 0 }}>
            <SwitchRow icon="pulse-outline" title={t('Vibrate on every tap')} value={prefs.pos.haptics} onChange={(v) => prefs.update({ pos: { haptics: v } })} />
          </Card>
        </Section>

        <Section title={t('Data on this phone')}>
          <Group>
            <ListRow icon="cloud-download-outline" iconTone="warning" title={t('Download everything again')} subtitle={t('Erase the phone\'s copy and get a fresh one')} onPress={resetData} />
          </Group>
        </Section>

        <Section title={t('About')}>
          <Group>
            <ListRow icon="information-circle-outline" iconTone="neutral" title="SHEZONI PatoPro" subtitle={`v${Application.nativeApplicationVersion ?? Constants.expoConfig?.version ?? '1.0.0'} (${installedBuild() || '—'})`} chevron={false} />
            {Platform.OS === 'android' ? (
              <ListRow icon="arrow-down-circle-outline" title={t('Check for updates')} subtitle={updateLabel} onPress={checkUpdate} />
            ) : null}
            <ListRow icon="server-outline" iconTone="neutral" title={t('Server')} subtitle={apiUrl} chevron={false} />
            <ListRow icon="phone-portrait-outline" iconTone="neutral" title={t('Phone ID')} subtitle={runtime.get().installationId?.slice(0, 8) ?? '—'} chevron={false} />
          </Group>
        </Section>
      </ScrollView>

      <Sheet visible={pinOpen} onClose={() => setPinOpen(false)} title={t('Set app PIN')} footer={<Button title={t('Save PIN')} onPress={savePin} />}>
        <View style={{ gap: space.md }}>
          <Input label={t('New PIN')} value={pin1} onChangeText={(v) => setPin1(v.replace(/\D/g, '').slice(0, 6))} keyboardType="number-pad" secureTextEntry autoFocus />
          <Input label={t('Repeat the PIN')} value={pin2} onChangeText={(v) => setPin2(v.replace(/\D/g, '').slice(0, 6))} keyboardType="number-pad" secureTextEntry />
        </View>
      </Sheet>
    </Screen>
  );
}
