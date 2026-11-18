import { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, View } from 'react-native';
import { router } from 'expo-router';
import { ApiError, api } from '@/lib/api';
import { useSession } from '@/lib/session';
import { useT } from '@/i18n';
import { Button, Card, EmptyState, Input, SkeletonList, SwitchRow, Text, space, toast, useColors } from '@/ui';
import { ScreenHeader } from '@/features/common';
import { useRemote } from '@/features/shop/data';

type Profile = { name: string; phone: string | null; alt_phone: string | null; email: string | null; address: string | null; city: string | null; sms_opt_in: boolean };

/** The customer updates their own details. */
export default function ProfileScreen() {
  const t = useT();
  const c = useColors();
  const remote = useRemote(() => api.get<{ profile: Profile }>('shop/account'), []);
  const p = remote.data?.profile;

  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <ScreenHeader title={t('Edit my details')} />
      {p ? <Form initial={p} /> : remote.error ? <EmptyState icon="cloud-offline-outline" tone="warning" title={remote.error.offline ? t('No internet') : t('Could not load your details')} text={remote.error.message} action={{ title: t('Try again'), onPress: remote.reload, icon: 'refresh' }} /> : <SkeletonList rows={5} avatar={false} />}
    </View>
  );
}

function Form({ initial }: { initial: Profile }) {
  const t = useT();
  const [f, setF] = useState({ name: initial.name ?? '', phone: initial.phone ?? '', alt_phone: initial.alt_phone ?? '', email: initial.email ?? '', address: initial.address ?? '', city: initial.city ?? '' });
  const [sms, setSms] = useState(!!initial.sms_opt_in);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const set = (k: keyof typeof f) => (v: string) => setF((x) => ({ ...x, [k]: v }));

  const save = async () => {
    setBusy(true);
    setErrors({});
    try {
      const res = await api.put('shop/account', { ...f, email: f.email.trim() || null, alt_phone: f.alt_phone.trim() || null, sms_opt_in: sms });
      toast.success(res.message ?? t('Your profile has been updated.'));
      await useSession.getState().refresh().catch(() => undefined);
      router.back();
    } catch (e) {
      if (e instanceof ApiError && e.errors) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
      toast.error(e instanceof ApiError ? (e.offline ? t('No internet. Try again when connected.') : e.firstError) : String(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: space.lg, gap: space.lg, paddingBottom: space.xxxl }}>
        <Card style={{ gap: space.md }}>
          <Input label={t('Full name')} value={f.name} onChangeText={set('name')} icon="person-outline" error={errors.name} />
          <Input label={t('Phone number')} value={f.phone} onChangeText={set('phone')} icon="call-outline" keyboardType="phone-pad" error={errors.phone} />
          <Input label={t('Other phone (optional)')} value={f.alt_phone} onChangeText={set('alt_phone')} icon="call-outline" keyboardType="phone-pad" error={errors.alt_phone} />
          <Input label={t('Email (optional)')} value={f.email} onChangeText={set('email')} icon="mail-outline" keyboardType="email-address" autoCapitalize="none" error={errors.email} />
          <Input label={t('Address (optional)')} value={f.address} onChangeText={set('address')} icon="location-outline" error={errors.address} />
          <Input label={t('Town / city (optional)')} value={f.city} onChangeText={set('city')} icon="business-outline" error={errors.city} />
        </Card>
        <SwitchRow title={t('Order updates by SMS')} subtitle={t('Also get a text message when your order moves on.')} value={sms} onChange={setSms} icon="chatbubble-outline" />
        <Button title={t('Save')} size="lg" icon="checkmark" loading={busy} onPress={save} />
        <Text variant="caption" tone="faint" center>
          {t('Your phone number is also what you use to sign in.')}
        </Text>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
