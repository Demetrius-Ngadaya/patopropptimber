import React, { useRef, useState } from 'react';
import { Image, KeyboardAvoidingView, Platform, Pressable, ScrollView, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { ApiError } from '@/lib/api';
import { useSession } from '@/lib/session';
import { usePrefs } from '@/lib/prefs';
import { useLocale, useT } from '@/i18n';
import { Badge, Button, Card, Input, Sheet, Text, radius, space, useColors } from '@/ui';

/** Sign in: "PatoPro by SHEZONI", then the shop's server, username and password (and the 2-step code). */
export default function SignIn() {
  const t = useT();
  const c = useColors();
  const { status, apiUrl, expired, login, verifyTwoFactor, setServer } = useSession();
  const locale = useLocale((s) => s.locale);
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [show, setShow] = useState(false);
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [serverOpen, setServerOpen] = useState(!apiUrl || apiUrl.includes('example.com'));
  const [server, setServerDraft] = useState(apiUrl.includes('example.com') ? '' : apiUrl);
  const passwordRef = useRef<React.ComponentRef<typeof TextInput>>(null);
  const twoFactor = status === 'twoFactor';

  const explain = (e: unknown) => {
    if (e instanceof ApiError) {
      if (e.offline) return t('Cannot reach the server. Check the internet connection and the server address.');
      return e.firstError;
    }
    return e instanceof Error ? e.message : String(e);
  };

  const submit = async () => {
    setError(null);
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

  const host = (() => {
    try {
      return new URL(apiUrl).host;
    } catch {
      return apiUrl || t('Not set');
    }
  })();

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
          </Card>

          <Pressable onPress={() => setServerOpen(true)} style={{ alignSelf: 'center', flexDirection: 'row', alignItems: 'center', gap: 6, padding: space.sm }}>
            <Ionicons name="server-outline" size={15} color={c.textFaint} />
            <Text variant="caption" tone="faint">
              {t('Server')}: {host}
            </Text>
            <Text variant="caption" tone="primary">
              {t('Change')}
            </Text>
          </Pressable>

          <Text variant="caption" tone="faint" center>
            © {new Date().getFullYear()} SHEZONI Technologies Ltd · PatoPro v1.0
          </Text>
        </ScrollView>
      </KeyboardAvoidingView>

      <Sheet
        visible={serverOpen}
        onClose={() => setServerOpen(false)}
        title={t('Server address')}
        footer={
          <Button
            title={t('Save')}
            onPress={async () => {
              await setServer(server);
              setServerOpen(false);
            }}
          />
        }
      >
        <View style={{ gap: space.md }}>
          <Text tone="muted">{t('The address of your PatoPro backend, as given by your administrator.')}</Text>
          <Input value={server} onChangeText={setServerDraft} placeholder="https://api.yourshop.co.tz" autoCapitalize="none" autoCorrect={false} keyboardType="url" icon="globe-outline" />
          <Badge label={t('Must start with https:// on the internet')} tone="info" icon="lock-closed" />
        </View>
      </Sheet>
    </SafeAreaView>
  );
}
