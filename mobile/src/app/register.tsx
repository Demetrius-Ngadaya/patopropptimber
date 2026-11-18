import { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, View } from 'react-native';
import { ApiError } from '@/lib/api';
import { useSession } from '@/lib/session';
import { useT } from '@/i18n';
import { Button, Card, Input, SwitchRow, Text, space, useColors } from '@/ui';
import { ScreenHeader } from '@/features/common';

/** A shop customer creates an account in the app (and is signed in straight away). */
export default function Register() {
  const t = useT();
  const c = useColors();
  const register = useSession((s) => s.register);
  const [f, setF] = useState({ name: '', phone: '', email: '', address: '', city: '', password: '', password_confirmation: '' });
  const [accept, setAccept] = useState(false);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const set = (k: keyof typeof f) => (v: string) => setF((x) => ({ ...x, [k]: v }));

  const submit = async () => {
    setError(null);
    setErrors({});
    if (!accept) {
      setError(t('Please accept the terms to create an account.'));
      return;
    }
    setBusy(true);
    try {
      await register({ ...f, email: f.email.trim() || null, accept_terms: true });
    } catch (e) {
      if (e instanceof ApiError) {
        if (e.errors) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
        setError(e.offline ? t('No internet. Creating an account needs internet.') : e.firstError);
      } else setError(String(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <ScreenHeader title={t('Create account')} subtitle={t('Order from the shop on your phone')} />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: space.lg, gap: space.lg }}>
          <Card style={{ gap: space.md }}>
            <Input label={t('Full name')} value={f.name} onChangeText={set('name')} icon="person-outline" error={errors.name} autoComplete="name" />
            <Input label={t('Phone number')} value={f.phone} onChangeText={set('phone')} icon="call-outline" keyboardType="phone-pad" placeholder="0712 345 678" error={errors.phone} autoComplete="tel" />
            <Input label={t('Email (optional)')} hint={t('Needed only if you want to reset a forgotten password by email.')} value={f.email} onChangeText={set('email')} icon="mail-outline" keyboardType="email-address" autoCapitalize="none" error={errors.email} />
            <Input label={t('Address (optional)')} value={f.address} onChangeText={set('address')} icon="location-outline" error={errors.address} />
            <Input label={t('Town / city (optional)')} value={f.city} onChangeText={set('city')} icon="business-outline" error={errors.city} />
          </Card>
          <Card style={{ gap: space.md }}>
            <Input label={t('Password')} value={f.password} onChangeText={set('password')} secureTextEntry icon="lock-closed-outline" error={errors.password} autoComplete="new-password" />
            <Input label={t('Confirm password')} value={f.password_confirmation} onChangeText={set('password_confirmation')} secureTextEntry icon="lock-closed-outline" autoComplete="new-password" />
          </Card>
          <SwitchRow title={t('I accept the terms of the shop')} value={accept} onChange={setAccept} icon="document-text-outline" />
          {error ? (
            <Text variant="label" tone="danger">
              {error}
            </Text>
          ) : null}
          <Button title={t('Create account')} size="lg" loading={busy} onPress={submit} icon="person-add-outline" />
          <Text variant="caption" tone="faint" center>
            {t('Already have an account? Go back and sign in.')}
          </Text>
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  );
}
