import { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, View } from 'react-native';
import { router } from 'expo-router';
import { ApiError, api } from '@/lib/api';
import { secure } from '@/lib/secure';
import { useSession } from '@/lib/session';
import { useT } from '@/i18n';
import { Button, Card, Input, Text, space, toast, useColors } from '@/ui';
import { ScreenHeader } from '@/features/common';

/** Change my password (staff and customers). Needs internet: the server checks the old one. */
export function ChangePasswordScreen() {
  const t = useT();
  const c = useColors();
  const user = useSession((s) => s.user);
  const branchId = useSession((s) => s.branchId);
  const [current, setCurrent] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);

  const save = async () => {
    setError(null);
    setErrors({});
    if (password !== confirmation) {
      setErrors({ password_confirmation: t('The two new passwords are not the same.') });
      return;
    }
    setBusy(true);
    try {
      const res = await api.post('auth/change-password', { current_password: current, password, password_confirmation: confirmation });
      // The offline sign-in on this phone now uses the new password.
      if (user?.type === 'staff') await secure.rememberOfflineLogin(user.username ?? user.phone ?? user.email ?? '', password, user, branchId).catch(() => undefined);
      toast.success(res.message ?? t('Password changed.'));
      router.back();
    } catch (e) {
      if (e instanceof ApiError) {
        if (e.errors) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
        setError(e.offline ? t('No internet. Changing the password needs internet.') : e.firstError);
      } else setError(String(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <ScreenHeader title={t('Change password')} />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: space.lg, gap: space.lg }}>
          <Card style={{ gap: space.md }}>
            <Input label={t('Current password')} value={current} onChangeText={setCurrent} secureTextEntry icon="lock-closed-outline" error={errors.current_password} autoComplete="current-password" />
            <Input label={t('New password')} value={password} onChangeText={setPassword} secureTextEntry icon="key-outline" error={errors.password} autoComplete="new-password" hint={t('At least 8 characters.')} />
            <Input label={t('Confirm new password')} value={confirmation} onChangeText={setConfirmation} secureTextEntry icon="key-outline" error={errors.password_confirmation} autoComplete="new-password" />
          </Card>
          {error ? (
            <Text variant="label" tone="danger">
              {error}
            </Text>
          ) : null}
          <Button title={t('Change password')} size="lg" icon="checkmark" loading={busy} disabled={!current || !password} onPress={save} />
          <Text variant="caption" tone="faint" center>
            {t('Your other phones and computers are signed out after the change.')}
          </Text>
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  );
}
