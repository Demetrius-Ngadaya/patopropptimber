import { useEffect, useState } from 'react';
import { haptic } from '@/lib/haptics';
import { Pressable, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { usePrefs } from '@/lib/prefs';
import { secure } from '@/lib/secure';
import { unlockWithBiometrics, useLock } from '@/lib/lock';
import { useSession } from '@/lib/session';
import { useT } from '@/i18n';
import { Button, Text, radius, space, useColors } from '@/ui';

/** Covers the app until the fingerprint / face or the PIN is given. */
export function LockScreen() {
  const t = useT();
  const c = useColors();
  const user = useSession((s) => s.user);
  const { biometric, pin: pinOn } = usePrefs((s) => s.security);
  const [pin, setPin] = useState('');
  const [wrong, setWrong] = useState(false);

  const tryBiometric = async () => {
    if (!biometric) return;
    if (await unlockWithBiometrics(t('Unlock PatoPro'), pinOn ? t('Use PIN') : t('Cancel'))) useLock.getState().unlock();
  };

  useEffect(() => {
    tryBiometric();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const press = async (digit: string) => {
    haptic.select();
    setWrong(false);
    const next = digit === 'del' ? pin.slice(0, -1) : (pin + digit).slice(0, 6);
    setPin(next);
    if (next.length >= 4 && (await secure.checkPin(next))) {
      haptic.success();
      useLock.getState().unlock();
    } else if (next.length === 6) {
      haptic.error();
      setWrong(true);
      setPin('');
    }
  };

  const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'bio', '0', 'del'];
  return (
    <SafeAreaView style={{ position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, backgroundColor: c.bg, zIndex: 999, alignItems: 'center', justifyContent: 'center', gap: space.xl, padding: space.xl }}>
      <View style={{ width: 64, height: 64, borderRadius: 20, backgroundColor: c.primary, alignItems: 'center', justifyContent: 'center' }}>
        <Ionicons name="lock-closed" size={28} color="#fff" />
      </View>
      <View style={{ alignItems: 'center', gap: 4 }}>
        <Text variant="title">{t('PatoPro is locked')}</Text>
        <Text tone="muted">{user?.name}</Text>
      </View>

      {pinOn ? (
        <>
          <View style={{ flexDirection: 'row', gap: 14, height: 18 }}>
            {Array.from({ length: Math.max(4, pin.length) }, (_, i) => (
              <View key={i} style={{ width: 14, height: 14, borderRadius: 7, backgroundColor: i < pin.length ? c.primary : 'transparent', borderWidth: 2, borderColor: wrong ? c.danger : c.primary }} />
            ))}
          </View>
          {wrong ? <Text tone="danger">{t('Wrong PIN. Try again.')}</Text> : null}
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', width: 270, justifyContent: 'space-between', rowGap: 14 }}>
            {keys.map((k) =>
              k === 'bio' && !biometric ? (
                <View key={k} style={{ width: 76, height: 76 }} />
              ) : (
                <Pressable
                  key={k}
                  onPress={() => (k === 'bio' ? tryBiometric() : press(k))}
                  accessibilityLabel={k === 'del' ? t('Delete') : k === 'bio' ? t('Fingerprint or face') : k}
                  style={({ pressed }) => ({ width: 76, height: 76, borderRadius: radius.pill, alignItems: 'center', justifyContent: 'center', backgroundColor: pressed ? c.surfacePressed : k === 'del' || k === 'bio' ? 'transparent' : c.surface })}
                >
                  {k === 'del' ? <Ionicons name="backspace-outline" size={26} color={c.text} /> : k === 'bio' ? <Ionicons name="finger-print" size={30} color={c.primary} /> : <Text variant="title">{k}</Text>}
                </Pressable>
              ),
            )}
          </View>
        </>
      ) : (
        <Button title={t('Unlock')} icon="finger-print" size="lg" onPress={tryBiometric} />
      )}

      <Button title={t('Sign out')} variant="ghost" onPress={() => useSession.getState().logout().then(() => useLock.getState().unlock())} />
    </SafeAreaView>
  );
}
