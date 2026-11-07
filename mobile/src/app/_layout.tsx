import { useEffect } from 'react';
import { View } from 'react-native';
import { Stack } from 'expo-router';
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

SplashScreen.preventAutoHideAsync().catch(() => undefined);
SplashScreen.setOptions({ duration: 250, fade: true });

export default function RootLayout() {
  const status = useSession((s) => s.status);
  const c = useColors();

  useEffect(() => {
    (async () => {
      await getDb();
      await usePrefs.getState().load();
      wireApp();
      await useSession.getState().boot();
    })().finally(() => SplashScreen.hideAsync().catch(() => undefined));
  }, []);

  useEffect(() => {
    SystemUI.setBackgroundColorAsync(c.bg).catch(() => undefined);
  }, [c.bg]);

  if (status === 'booting') return <View style={{ flex: 1, backgroundColor: c.bg }} />;

  const signedIn = status === 'ready';
  return (
    <GestureHandlerRootView style={{ flex: 1 }}>
      <SafeAreaProvider>
        <StatusBar style={c.scheme === 'dark' ? 'light' : 'dark'} />
        <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: c.bg }, animation: 'slide_from_right' }}>
          <Stack.Protected guard={signedIn}>
            <Stack.Screen name="(app)" />
          </Stack.Protected>
          <Stack.Protected guard={!signedIn}>
            <Stack.Screen name="sign-in" options={{ animation: 'fade' }} />
          </Stack.Protected>
        </Stack>
        <ToastHost />
        <ConfirmHost />
      </SafeAreaProvider>
    </GestureHandlerRootView>
  );
}
