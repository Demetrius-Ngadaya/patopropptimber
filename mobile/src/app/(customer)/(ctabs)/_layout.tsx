import { type ColorValue } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Tabs } from 'expo-router/js-tabs';
import { Ionicons } from '@expo/vector-icons';
import { haptic } from '@/lib/haptics';
import { useT } from '@/i18n';
import { useColors } from '@/ui';
import { basketCount, useBasket } from '@/features/shop/data';

type IconName = React.ComponentProps<typeof Ionicons>['name'];

export const unstable_settings = { anchor: 'shop' };

export default function CustomerTabs() {
  const t = useT();
  const c = useColors();
  const insets = useSafeAreaInsets();
  const count = useBasket((s) => basketCount(s.items));

  const icon = (name: IconName, focused: IconName) =>
    function TabIcon({ color, focused: on }: { color: ColorValue; focused: boolean }) {
      return <Ionicons name={on ? focused : name} size={24} color={color as string} />;
    };

  return (
    <Tabs
      backBehavior="initialRoute"
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: c.primary,
        tabBarInactiveTintColor: c.textFaint,
        tabBarStyle: { backgroundColor: c.surface, borderTopColor: c.border, height: 60 + Math.max(insets.bottom, 8), paddingTop: 6, paddingBottom: Math.max(insets.bottom, 8) },
        tabBarLabelStyle: { fontSize: 11, fontWeight: '700' },
        sceneStyle: { backgroundColor: c.bg },
      }}
      screenListeners={{ tabPress: () => haptic.select() }}
    >
      <Tabs.Screen name="shop" options={{ title: t('Shop'), tabBarIcon: icon('storefront-outline', 'storefront') }} />
      <Tabs.Screen
        name="basket"
        options={{
          title: t('Basket'),
          tabBarIcon: icon('basket-outline', 'basket'),
          tabBarBadge: count > 0 ? (count > 99 ? '99+' : count) : undefined,
          tabBarBadgeStyle: { backgroundColor: c.accent, fontSize: 10, fontWeight: '800' },
        }}
      />
      <Tabs.Screen name="my-orders" options={{ title: t('My orders'), tabBarIcon: icon('receipt-outline', 'receipt') }} />
      <Tabs.Screen name="account" options={{ title: t('Account'), tabBarIcon: icon('person-circle-outline', 'person-circle') }} />
    </Tabs>
  );
}
