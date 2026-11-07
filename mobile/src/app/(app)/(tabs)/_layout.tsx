import { Platform, type ColorValue } from 'react-native';
import { haptic } from '@/lib/haptics';
import { Tabs } from 'expo-router/js-tabs';
import { Ionicons } from '@expo/vector-icons';
import { useCan } from '@/lib/session';
import { useLive } from '@/lib/useLive';
import { countOpenOrders } from '@/db/repo';
import { useT } from '@/i18n';
import { useColors } from '@/ui';

type IconName = React.ComponentProps<typeof Ionicons>['name'];

export default function TabsLayout() {
  const t = useT();
  const c = useColors();
  const canSell = useCan('pos.access');
  const canOrders = useCan('orders.view');
  const canProducts = useCan('products.view|pos.access');
  const open = useLive(countOpenOrders, ['orders'], []).data ?? 0;

  const icon = (name: IconName, focused: IconName) =>
    function TabIcon({ color, focused: on }: { color: ColorValue; focused: boolean }) {
      return <Ionicons name={on ? focused : name} size={24} color={color as string} />;
    };

  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: c.primary,
        tabBarInactiveTintColor: c.textFaint,
        tabBarStyle: {
          backgroundColor: c.surface,
          borderTopColor: c.border,
          height: Platform.OS === 'ios' ? 86 : 66,
          paddingTop: 6,
          paddingBottom: Platform.OS === 'ios' ? 28 : 10,
        },
        tabBarLabelStyle: { fontSize: 11, fontWeight: '700' },
        sceneStyle: { backgroundColor: c.bg },
      }}
      screenListeners={{ tabPress: () => haptic.select() }}
    >
      <Tabs.Screen name="index" options={{ title: t('Home'), tabBarIcon: icon('home-outline', 'home') }} />
      <Tabs.Screen name="sell" options={{ title: t('Sell'), href: canSell ? undefined : null, tabBarIcon: icon('cart-outline', 'cart') }} />
      <Tabs.Screen
        name="orders"
        options={{
          title: t('Orders'),
          href: canOrders ? undefined : null,
          tabBarIcon: icon('receipt-outline', 'receipt'),
          tabBarBadge: open > 0 ? (open > 99 ? '99+' : open) : undefined,
          tabBarBadgeStyle: { backgroundColor: c.danger, fontSize: 10, fontWeight: '800' },
        }}
      />
      <Tabs.Screen name="products" options={{ title: t('Stock'), href: canProducts ? undefined : null, tabBarIcon: icon('cube-outline', 'cube') }} />
      <Tabs.Screen name="more" options={{ title: t('More'), tabBarIcon: icon('grid-outline', 'grid') }} />
    </Tabs>
  );
}
