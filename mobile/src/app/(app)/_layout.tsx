import { useEffect } from 'react';
import { Stack, router } from 'expo-router';
import { attachNotificationListeners, takePendingRoute } from '@/notifications';
import { requestSync } from '@/sync/manager';
import { useLock, watchAppLock } from '@/lib/lock';
import { useColors } from '@/ui';
import { LockScreen } from '@/features/LockScreen';
import { UpdateNotice } from '@/features/AppUpdate';

export default function AppLayout() {
  const c = useColors();
  const locked = useLock((s) => s.locked);

  useEffect(() => {
    const detach = attachNotificationListeners((data) => {
      // A push arrived while the app is open: fetch what it is about right away.
      requestSync('push', { only: data.category === 'orders' ? ['orders', 'notifications'] : ['notifications'] });
    });
    const unwatch = watchAppLock();
    return () => {
      detach();
      unwatch();
    };
  }, []);

  // A notification tapped before sign-in / unlock opens now.
  useEffect(() => {
    if (locked) return;
    const route = takePendingRoute();
    if (route) setTimeout(() => router.push(route as never), 300);
  }, [locked]);

  return (
    <>
      <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: c.bg }, animation: 'slide_from_right' }}>
        <Stack.Screen name="(tabs)" />
        <Stack.Screen name="checkout" options={{ presentation: 'modal', animation: 'slide_from_bottom' }} />
        <Stack.Screen name="scan" options={{ presentation: 'fullScreenModal', animation: 'fade' }} />
      </Stack>
      <UpdateNotice />
      {locked ? <LockScreen /> : null}
    </>
  );
}
