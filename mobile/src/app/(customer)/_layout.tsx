import { useEffect } from 'react';
import { Stack, router } from 'expo-router';
import { attachNotificationListeners, takePendingRoute } from '@/notifications';
import { useColors } from '@/ui';
import { UpdateNotice } from '@/features/AppUpdate';
import { useBasket } from '@/features/shop/data';
import { useOrderAlerts } from '@/features/shop/alerts';

/** The shop for customers: browse, basket, their orders and account. */
export const unstable_settings = { anchor: '(ctabs)' };

export default function CustomerLayout() {
  const c = useColors();
  const bump = useOrderAlerts((s) => s.bump);

  useEffect(() => {
    useBasket.getState().load().catch(() => undefined);
    // An order update arrived while the app is open: refresh the order screens.
    const detach = attachNotificationListeners(() => bump());
    const route = takePendingRoute();
    if (route) setTimeout(() => router.push(route as never), 300);
    return detach;
  }, [bump]);

  return (
    <>
      <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: c.bg }, animation: 'slide_from_right' }}>
        <Stack.Screen name="(ctabs)" />
      </Stack>
      <UpdateNotice />
    </>
  );
}
