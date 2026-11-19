import React, { useRef, useState } from 'react';
import { Image, KeyboardAvoidingView, Platform, Pressable, ScrollView, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ApiError, api } from '@/lib/api';
import { useSession } from '@/lib/session';
import { ServerSheet } from '@/features/ServerSheet';
import { usePrefs } from '@/lib/prefs';
import { useLocale, useT } from '@/i18n';
import { Button, Card, Input, Sheet, Text, radius, space, useColors } from '@/ui';

/** Sign in: "PatoPro by SHEZONI", username and password (and the 2-step code). The server address is never shown. */
export default function SignIn() {
  const t = useT();
  const c = useColors();
  const { status, apiUrl, expired, login, verifyTwoFactor } = useSession();
  const locale = useLocale((s) => s.locale);
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [show, setShow] = useState(false);
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [serverOpen, setServerOpen] = useState(!apiUrl || apiUrl.includes('example.com'));
  const passwordRef = useRef<React.ComponentRef<typeof TextInput>>(null);
  const [forgotOpen, setForgotOpen] = useState(false);
  const [email, setEmail] = useState('');
  const [forgotBusy, setForgotBusy] = useState(false);
  const [forgotDone, setForgotDone] = useState<string | null>(null);
  const [forgotError, setForgotError] = useState<string | null>(null);
  const twoFactor = status === 'twoFactor';

  // Every failure says what actually went wrong: wrong password, locked account,
  // no internet on the phone, or the server not answering.
  const explain = (e: unknown) => {
    if (e instanceof ApiError) {
      if (['OFFLINE_FIRST', 'OFFLINE_STALE', 'OFFLINE_WRONG', 'SERVER_UNREACHABLE'].includes(e.code ?? '')) return e.message;
      if (e.status === 429) return e.firstError || t('Too many attempts. Wait a minute and try again.');
      if (e.offline) return t('Cannot reach the PatoPro server. Check that the phone has internet (data bundle / Wi-Fi) and try again.');
      return e.firstError;
    }
    return e instanceof Error ? e.message : String(e);
  };

  const sendReset = async () => {
    setForgotError(null);
    setForgotDone(null);
    if (!/^\S+@\S+\.\S+$/.test(email.trim())) {
      setForgotError(t('Enter the email address of your account.'));
      return;
    }
    setForgotBusy(true);
    try {
      const res = await api.post('auth/forgot-password', { email: email.trim() }, { auth: false });
      setForgotDone(res.message ?? t('If an account with that email exists, a reset link has been sent.'));
    } catch (e) {
      setForgotError(explain(e));
    } finally {
      setForgotBusy(false);
    }
  };

  const submit = async () => {
    setError(null);
    // The Software Engineer's code: opens the server address (hidden from everyone else).
    if (!twoFactor && identifier.trim() === '111999') {
      setIdentifier('');
      setPassword('');
      setServerOpen(true);
      return;
    }
    if (!twoFactor && (!identifier.trim() || !password)) {
      setError(t('Enter your username and password.'));
      return;
    }
    setBusy(true);
    try {
      if (twoFactor) await verifyTwoFactor(code.trim());
      else await login(identifier.trim(), password);
    } catch (e) {
      setError(explain(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: c.bg }} edges={['top', 'bottom']}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ flexGrow: 1, padding: space.xl, justifyContent: 'center', gap: space.xl }}>
          <View style={{ flexDirection: 'row', justifyContent: 'flex-end' }}>
            <Pressable
              onPress={() => usePrefs.getState().update({ locale: locale === 'sw' ? 'en' : 'sw' })}
              style={{ flexDirection: 'row', alignItems: 'center', gap: 6, paddingHorizontal: 12, height: 34, borderRadius: radius.pill, backgroundColor: c.surface, borderWidth: 1, borderColor: c.border }}
              accessibilityLabel={t('Language')}
            >
              <Ionicons name="language" size={16} color={c.textMuted} />
              <Text variant="label">{locale === 'sw' ? 'Kiswahili' : 'English'}</Text>
            </Pressable>
          </View>

          <View style={{ alignItems: 'center', gap: space.sm }}>
            <Image source={require('../../assets/splash-icon.png')} style={{ width: 76, height: 76, borderRadius: 20 }} />
            <Text variant="display" style={{ marginTop: space.sm }}>
              <Text variant="display" style={{ color: c.primary }}>
                Pato
              </Text>
              <Text variant="display" style={{ color: c.accent, fontWeight: '600' }}>
                Pro
              </Text>
            </Text>
            <Text variant="caption" tone="faint" style={{ letterSpacing: 2 }}>
              BY SHEZONI
            </Text>
            <Text tone="muted" center style={{ marginTop: space.sm }}>
              {twoFactor ? t('Enter the 6-digit code from your authenticator app.') : t('Welcome back! Sign in to continue.')}
            </Text>
          </View>

          {expired ? (
            <Card style={{ backgroundColor: c.warningSoft, borderColor: c.warningSoft, flexDirection: 'row', gap: space.md, alignItems: 'center' }}>
              <Ionicons name="time-outline" size={22} color={c.warning} />
              <Text variant="label" style={{ flex: 1, color: c.warning }}>
                {t('Your login ended. Sign in again: sales made offline are kept and will be sent.')}
              </Text>
            </Card>
          ) : null}

          <Card style={{ gap: space.lg }} elevated>
            {twoFactor ? (
              <Input
                label={t('Verification code')}
                value={code}
                onChangeText={setCode}
                keyboardType="number-pad"
                autoFocus
                maxLength={20}
                icon="shield-checkmark-outline"
                placeholder="123 456"
                onSubmitEditing={submit}
              />
            ) : (
              <>
                <Input
                  label={t('Username, email or phone')}
                  value={identifier}
                  onChangeText={setIdentifier}
                  autoCapitalize="none"
                  autoCorrect={false}
                  autoComplete="username"
                  textContentType="username"
                  icon="person-outline"
                  returnKeyType="next"
                  onSubmitEditing={() => passwordRef.current?.focus()}
                />
                <Input
                  ref={passwordRef}
                  label={t('Password')}
                  value={password}
                  onChangeText={setPassword}
                  secureTextEntry={!show}
                  autoComplete="password"
                  textContentType="password"
                  icon="lock-closed-outline"
                  returnKeyType="go"
                  onSubmitEditing={submit}
                  right={
                    <Pressable onPress={() => setShow((v) => !v)} hitSlop={10} accessibilityLabel={show ? t('Hide password') : t('Show password')}>
                      <Ionicons name={show ? 'eye-off-outline' : 'eye-outline'} size={20} color={c.textFaint} />
                    </Pressable>
                  }
                />
              </>
            )}
            {error ? (
              <View style={{ flexDirection: 'row', gap: space.sm, alignItems: 'flex-start' }}>
                <Ionicons name="alert-circle" size={18} color={c.danger} />
                <Text variant="label" tone="danger" style={{ flex: 1 }}>
                  {error}
                </Text>
              </View>
            ) : null}
            <Button title={twoFactor ? t('Verify') : t('Sign in')} size="lg" loading={busy} onPress={submit} iconRight="arrow-forward" />
            {twoFactor ? <Button title={t('Back')} variant="ghost" onPress={() => useSession.setState({ status: 'signedOut', challenge: null })} /> : null}
            {!twoFactor ? (
              <Pressable onPress={() => { setForgotOpen(true); setForgotDone(null); setForgotError(null); }} hitSlop={8} style={{ alignSelf: 'center' }}>
                <Text variant="label" tone="primary">
                  {t('Forgot password?')}
                </Text>
              </Pressable>
            ) : null}
          </Card>

          {!twoFactor ? (
            <Card style={{ flexDirection: 'row', alignItems: 'center', gap: space.md }}>
              <Ionicons name="bag-handle-outline" size={22} color={c.accent} />
              <View style={{ flex: 1 }}>
                <Text variant="bodyStrong">{t('Shopping with us?')}</Text>
              </View>
              <Button title={t('Create account')} size="sm" variant="secondary" onPress={() => router.push('/register')} />
            </Card>
          ) : null}


          <Text variant="caption" tone="faint" center>
            © {new Date().getFullYear()} SHEZONI Technologies Ltd · PatoPro
          </Text>
        </ScrollView>
      </KeyboardAvoidingView>

      <Sheet
        visible={forgotOpen}
        onClose={() => setForgotOpen(false)}
        title={t('Forgot password')}
        footer={forgotDone ? <Button title={t('Done')} onPress={() => setForgotOpen(false)} /> : <Button title={t('Send reset link')} icon="mail-outline" loading={forgotBusy} onPress={sendReset} />}
      >
        <View style={{ gap: space.md }}>
          <Text tone="muted">{t('Enter the email address of your account. We will send you a link to choose a new password. No email on your account? Ask the shop administrator to reset it.')}</Text>
          <Input value={email} onChangeText={setEmail} placeholder="jina@mfano.co.tz" autoCapitalize="none" autoCorrect={false} keyboardType="email-address" icon="mail-outline" />
          {forgotError ? <Text variant="label" tone="danger">{forgotError}</Text> : null}
          {forgotDone ? <Text variant="label" tone="primary">{forgotDone}</Text> : null}
        </View>
      </Sheet>

      <ServerSheet visible={serverOpen} onClose={() => setServerOpen(false)} />
    </SafeAreaView>
  );
}
