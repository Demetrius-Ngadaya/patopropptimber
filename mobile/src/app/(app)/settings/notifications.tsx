import { useEffect, useState } from 'react';
import { haptic } from '@/lib/haptics';
import { Linking, Pressable, ScrollView, View } from 'react-native';
import * as Notifications from 'expo-notifications';
import { Ionicons } from '@expo/vector-icons';
import { usePrefs } from '@/lib/prefs';
import { NOTIFICATION_CATEGORIES } from '@/db/categories';
import { pushStatus, registerDevice, sendTest, type PushStatus } from '@/notifications';
import { hasVoiceFor, speak } from '@/notifications/voice';
import { useLocale, useT } from '@/i18n';
import { Badge, Button, Card, IconTile, Input, Screen, Section, Segmented, SwitchRow, Text, radius, space, toast, useColors, type IconName } from '@/ui';
import { CATEGORY_META, ScreenHeader } from '@/features/common';
import type { AlertMode, NotificationCategory } from '@/lib/types';

const DESCRIPTION: Record<NotificationCategory, string> = {
  orders: 'New online orders and online payments',
  stock: 'Low stock, out of stock, morning stock summary',
  money: 'Sales reports, overdue debts, supplier payments, cancelled sales',
  team: 'Leave, shifts, payroll, purchase plans, note reminders',
  general: 'Everything else',
};

/**
 * For each kind of notification: on/off, and how it alerts — voice (the phone
 * reads it out), sound, vibration, in any mix. Applies to the notification
 * bar too: the server sends each push already shaped for this phone.
 */
export default function NotificationSettings() {
  const t = useT();
  const c = useColors();
  const prefs = usePrefs();
  const locale = useLocale((s) => s.locale);
  const [status, setStatus] = useState<PushStatus>(pushStatus());
  const [swVoice, setSwVoice] = useState(true);
  const n = prefs.notifications;

  useEffect(() => {
    registerDevice(false).then(setStatus).catch(() => undefined);
    hasVoiceFor('sw').then(setSwVoice);
  }, []);

  const allow = async () => {
    const res = await Notifications.requestPermissionsAsync();
    if (res.status === 'granted') setStatus(await registerDevice(false));
    else Linking.openSettings();
  };

  const test = async (category: NotificationCategory) => {
    const how = await sendTest(category);
    toast.success(how === 'push' ? t('Test sent through the server. It arrives in a few seconds.') : t('Test shown on this phone.'));
  };

  const pushText =
    status.permission !== 'granted'
      ? t('Notifications are blocked for PatoPro on this phone.')
      : status.token
        ? t('Push is on: alerts arrive instantly, even when the app is closed.')
        : status.reason === 'no_project'
          ? t('Instant push is not set up in this build. Notifications still arrive with every sync.')
          : t('Push could not start yet (no internet?). Notifications still arrive with every sync.');

  return (
    <Screen padded={false} edges={[]}>
      <ScreenHeader title={t('Notification sounds & voice')} />
      <ScrollView contentContainerStyle={{ padding: space.lg, paddingBottom: space.xxxl }}>
        <Card style={{ flexDirection: 'row', gap: space.md, alignItems: 'center', backgroundColor: status.permission !== 'granted' ? c.dangerSoft : status.token ? c.accentSoft : c.warningSoft, borderWidth: 0 }}>
          <Ionicons name={status.permission !== 'granted' ? 'notifications-off' : status.token ? 'notifications' : 'cloud-download'} size={26} color={status.permission !== 'granted' ? c.danger : status.token ? c.accent : c.warning} />
          <Text variant="label" style={{ flex: 1 }}>
            {pushText}
          </Text>
          {status.permission !== 'granted' ? <Button title={t('Allow')} size="sm" onPress={allow} /> : null}
        </Card>

        {NOTIFICATION_CATEGORIES.map((category) => (
          <CategoryCard key={category} category={category} mode={n.categories[category]} onChange={(m) => prefs.setAlert(category, m)} onTest={() => test(category)} description={t(DESCRIPTION[category])} />
        ))}

        <Section title={t('Voice')}>
          <Card style={{ gap: space.md }}>
            <Text variant="label" tone="muted">
              {t('Language of the voice')}
            </Text>
            <Segmented
              value={n.voiceLanguage}
              onChange={(v) => prefs.update({ notifications: { voiceLanguage: v } })}
              options={[
                { value: 'auto', label: t('Like the app') },
                { value: 'sw', label: 'Kiswahili' },
                { value: 'en', label: 'English' },
              ]}
            />
            {!swVoice && (n.voiceLanguage === 'sw' || (n.voiceLanguage === 'auto' && locale === 'sw')) ? (
              <Badge label={t('No Kiswahili voice on this phone: English is used. Install it in phone Settings → Text-to-speech.')} tone="warning" icon="information-circle" style={{ alignSelf: 'stretch' }} />
            ) : null}
            <Text variant="label" tone="muted">
              {t('Speed')}
            </Text>
            <Segmented
              value={String(n.voiceRate)}
              onChange={(v) => prefs.update({ notifications: { voiceRate: Number(v) } })}
              options={[
                { value: '0.8', label: t('Slow') },
                { value: '1', label: t('Normal') },
                { value: '1.2', label: t('Fast') },
              ]}
            />
            <Button title={t('Hear the voice')} icon="volume-high-outline" variant="secondary" onPress={() => speak(t('New online order ORD-1024. Asha ordered for TZS 45,000.'))} />
          </Card>
        </Section>

        <Section title={t('Quiet hours')}>
          <Card style={{ paddingVertical: 0 }}>
            <SwitchRow
              icon="moon-outline"
              title={t('Quiet hours')}
              subtitle={t('Notifications still arrive, without voice, sound or vibration.')}
              value={n.quietHours.enabled}
              onChange={(v) => prefs.update({ notifications: { quietHours: { enabled: v } } })}
            />
            {n.quietHours.enabled ? (
              <View style={{ flexDirection: 'row', gap: space.md, paddingBottom: space.lg }}>
                <TimeBox label={t('From')} value={n.quietHours.from} onChange={(v) => prefs.update({ notifications: { quietHours: { from: v } } })} />
                <TimeBox label={t('To')} value={n.quietHours.to} onChange={(v) => prefs.update({ notifications: { quietHours: { to: v } } })} />
              </View>
            ) : null}
          </Card>
        </Section>

        <Button title={t('Phone notification settings')} icon="open-outline" variant="ghost" style={{ marginTop: space.lg }} onPress={() => Linking.openSettings()} />
      </ScrollView>
    </Screen>
  );
}

function CategoryCard({ category, mode, onChange, onTest, description }: { category: NotificationCategory; mode: AlertMode; onChange: (m: Partial<AlertMode>) => void; onTest: () => void; description: string }) {
  const t = useT();
  const meta = CATEGORY_META[category];
  const options: { key: keyof Omit<AlertMode, 'enabled'>; icon: IconName; label: string }[] = [
    { key: 'voice', icon: 'mic', label: t('Voice') },
    { key: 'sound', icon: 'musical-notes', label: t('Sound') },
    { key: 'vibrate', icon: 'phone-portrait', label: t('Vibration') },
  ];
  const summary = !mode.enabled
    ? t('Off')
    : [mode.voice && t('Voice'), mode.sound && t('Sound'), mode.vibrate && t('Vibration')].filter(Boolean).join(' + ') || t('Silent (notification bar only)');
  return (
    <Card style={{ marginTop: space.lg, gap: space.md }}>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: space.md }}>
        <IconTile name={meta.icon} tone={mode.enabled ? meta.tone : 'neutral'} size={42} />
        <View style={{ flex: 1 }}>
          <Text variant="heading">{t(meta.label)}</Text>
          <Text variant="caption" tone="muted">
            {description}
          </Text>
        </View>
      </View>
      <SwitchRow title={t('Notify me')} subtitle={summary} value={mode.enabled} onChange={(v) => onChange({ enabled: v })} />
      {mode.enabled ? (
        <>
          <View style={{ flexDirection: 'row', gap: space.sm }}>
            {options.map((o) => (
              <Toggle key={o.key} icon={o.icon} label={o.label} on={mode[o.key]} onPress={() => onChange({ [o.key]: !mode[o.key] })} />
            ))}
          </View>
          <Button title={t('Send a test')} icon="paper-plane-outline" variant="secondary" size="sm" onPress={onTest} />
        </>
      ) : null}
    </Card>
  );
}

function Toggle({ icon, label, on, onPress }: { icon: IconName; label: string; on: boolean; onPress: () => void }) {
  const c = useColors();
  return (
    <Pressable
      accessibilityRole="switch"
      accessibilityState={{ checked: on }}
      accessibilityLabel={label}
      onPress={() => {
        haptic.select();
        onPress();
      }}
      style={({ pressed }) => ({
        flex: 1,
        alignItems: 'center',
        gap: 6,
        paddingVertical: space.md,
        borderRadius: radius.md,
        borderWidth: 2,
        borderColor: on ? c.primary : c.border,
        backgroundColor: on ? c.primarySoft : pressed ? c.surfacePressed : c.surface,
      })}
    >
      <Ionicons name={on ? icon : (`${icon}-outline` as IconName)} size={22} color={on ? c.primary : c.textFaint} />
      <Text variant="caption" style={{ fontWeight: '700', color: on ? c.primary : c.textMuted }}>
        {label}
      </Text>
      <Ionicons name={on ? 'checkmark-circle' : 'ellipse-outline'} size={16} color={on ? c.primary : c.borderStrong} />
    </Pressable>
  );
}

function TimeBox({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  const [draft, setDraft] = useState(value);
  return (
    <Input
      containerStyle={{ flex: 1 }}
      label={label}
      value={draft}
      icon="time-outline"
      keyboardType="numbers-and-punctuation"
      maxLength={5}
      placeholder="22:00"
      onChangeText={(raw) => {
        const digits = raw.replace(/[^0-9]/g, '').slice(0, 4);
        const v = digits.length > 2 ? `${digits.slice(0, 2)}:${digits.slice(2)}` : digits;
        setDraft(v);
        if (/^([01]\d|2[0-3]):[0-5]\d$/.test(v)) onChange(v);
      }}
    />
  );
}
