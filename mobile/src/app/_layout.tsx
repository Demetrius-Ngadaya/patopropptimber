import { useCallback, useEffect, useState } from 'react';
import { View } from 'react-native';
import { Stack, router, usePathname } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import * as SplashScreen from 'expo-splash-screen';
import * as SystemUI from 'expo-system-ui';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { useSession } from '@/lib/session';
import { usePrefs } from '@/lib/prefs';
import { wireApp } from '@/lib/wiring';
import { getDb } from '@/db/database';
import { ConfirmHost, ToastHost, useColors } from '@/ui';
import { AnimatedSplash } from '@/features/AnimatedSplash';

SplashScreen.preventAutoHideAsync().catch(() => undefined);
SplashScreen.setOptions({ duration: 250, fade: true });

export default function RootLayout() {
  const status = useSession((s) => s.status);
  const customer = useSession((s) => s.user?.type === 'customer');
  const c = useColors();
  const [intro, setIntro] = useState(true);
  const endIntro = useCallback(() => setIntro(false), []);

  useEffect(() => {
    (async () => {
      await getDb();
      await usePrefs.getState().load();
      wireApp();
      await useSession.getState().boot();
    })().finally(() => undefined);
    // The phone's splash hands over to the animated one straight away (same logo and colour).
    SplashScreen.hideAsync().catch(() => undefined);
  }, []);

  useEffect(() => {
    SystemUI.setBackgroundColorAsync(c.bg).catch(() => undefined);
  }, [c.bg]);

  // Customers start in the shop ("/" is the staff home screen).
  const path = usePathname();
  useEffect(() => {
    if (status !== 'ready' || !customer) return;
    if (path === '/' || path.startsWith('/(') || path === '/sign-in' || path === '/register') {
      const id = setTimeout(() => router.replace('/shop'), 0);
      return () => clearTimeout(id);
    }
  }, [status, customer, path]);

  const signedIn = status === 'ready';
  // One splash for the whole start-up (it stays mounted while the app loads behind it).
  const splash = intro ? <AnimatedSplash ready={status !== 'booting'} onDone={endIntro} /> : null;
  return (
    <GestureHandlerRootView style={{ flex: 1, backgroundColor: c.bg }}>
      <SafeAreaProvider>
        <StatusBar style={c.scheme === 'dark' ? 'light' : 'dark'} />
        {status === 'booting' ? (
          <View style={{ flex: 1, backgroundColor: c.bg }} />
        ) : (
        <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: c.bg }, animation: 'slide_from_right' }}>
          {/* Staff: the till, orders and stock (works offline). */}
          <Stack.Protected guard={signedIn && !customer}>
            <Stack.Screen name="(app)" />
          </Stack.Protected>
          {/* Customers: the online shop, their orders and account. */}
          <Stack.Protected guard={signedIn && customer}>
            <Stack.Screen name="(customer)" />
          </Stack.Protected>
          <Stack.Protected guard={!signedIn}>
            <Stack.Screen name="sign-in" options={{ animation: 'fade' }} />
            <Stack.Screen name="register" />
          </Stack.Protected>
        </Stack>
        )}
        <ToastHost />
        <ConfirmHost />
        {splash}
      </SafeAreaProvider>
    </GestureHandlerRootView>
  );
}
