import { useRef, useState } from 'react';
import { haptic } from '@/lib/haptics';
import { Pressable, StyleSheet, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { CameraView, useCameraPermissions, type BarcodeScanningResult } from 'expo-camera';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { findByCode } from '@/db/repo';
import { money } from '@/lib/format';
import { useCart } from '@/features/pos/cart';
import { useT } from '@/i18n';
import { Button, EmptyState, Screen, Text, radius, space, useColors } from '@/ui';

/**
 * Scan barcodes into the cart, one after another (or look a product up with
 * ?mode=lookup). Works offline: codes are matched on the phone's copy.
 */
export default function Scan() {
  const t = useT();
  const c = useColors();
  const insets = useSafeAreaInsets();
  const { mode } = useLocalSearchParams<{ mode?: string }>();
  const lookup = mode === 'lookup';
  const [permission, requestPermission] = useCameraPermissions();
  const [torch, setTorch] = useState(false);
  const [last, setLast] = useState<{ ok: boolean; text: string } | null>(null);
  const [count, setCount] = useState(0);
  const recent = useRef<{ code: string; at: number } | null>(null);
  const busy = useRef(false);

  const onScan = async ({ data }: BarcodeScanningResult) => {
    const now = Date.now();
    // The same code held in front of the camera is read many times a second.
    if (busy.current || (recent.current?.code === data && now - recent.current.at < 1800)) return;
    busy.current = true;
    recent.current = { code: data, at: now };
    try {
      const found = await findByCode(data);
      if (!found) {
        haptic.error();
        setLast({ ok: false, text: t('No product with code {code}', { code: data }) });
        return;
      }
      haptic.success();
      if (lookup) {
        router.replace(`/products/${found.product.id}`);
        return;
      }
      useCart.getState().add(found.product, found.unit);
      setCount((n) => n + 1);
      setLast({ ok: true, text: `${found.unit ? `${found.unit.name} · ` : ''}${found.product.name} · ${money(found.unit?.retail_price ?? found.product.selling_price)}` });
    } finally {
      busy.current = false;
    }
  };

  if (!permission) return <View style={{ flex: 1, backgroundColor: '#000' }} />;
  if (!permission.granted) {
    return (
      <Screen>
        <EmptyState
          icon="camera-outline"
          title={t('Allow the camera to scan barcodes')}
          text={t('PatoPro only uses the camera while this screen is open.')}
          action={{ title: t('Allow camera'), onPress: requestPermission, icon: 'checkmark' }}
        />
        <Button title={t('Close')} variant="ghost" onPress={() => router.back()} />
      </Screen>
    );
  }

  return (
    <View style={{ flex: 1, backgroundColor: '#000' }}>
      <CameraView
        style={StyleSheet.absoluteFill}
        facing="back"
        enableTorch={torch}
        barcodeScannerSettings={{ barcodeTypes: ['ean13', 'ean8', 'upc_a', 'upc_e', 'code128', 'code39', 'code93', 'itf14', 'qr'] }}
        onBarcodeScanned={onScan}
      />
      {/* Frame */}
      <View pointerEvents="none" style={[StyleSheet.absoluteFill, { alignItems: 'center', justifyContent: 'center' }]}>
        <View style={{ width: 270, height: 170, borderRadius: radius.xl, borderWidth: 3, borderColor: last?.ok === false ? '#F87171' : '#4ADE80' }} />
        <Text variant="label" style={{ color: '#fff', marginTop: space.lg }}>
          {lookup ? t('Point at a barcode to open the product') : t('Point at barcodes: each one is added to the cart')}
        </Text>
      </View>

      <View style={{ position: 'absolute', top: insets.top + space.sm, left: space.lg, right: space.lg, flexDirection: 'row', justifyContent: 'space-between' }}>
        <Round icon="close" label={t('Close')} onPress={() => router.back()} />
        <Round icon={torch ? 'flash' : 'flash-outline'} label={t('Torch')} onPress={() => setTorch((v) => !v)} />
      </View>

      <View style={{ position: 'absolute', left: space.lg, right: space.lg, bottom: insets.bottom + space.lg, gap: space.md }}>
        {last ? (
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: space.sm, backgroundColor: last.ok ? 'rgba(22,163,74,0.92)' : 'rgba(198,40,40,0.92)', borderRadius: radius.lg, padding: space.md }}>
            <Ionicons name={last.ok ? 'checkmark-circle' : 'alert-circle'} size={22} color="#fff" />
            <Text variant="bodyStrong" style={{ color: '#fff', flex: 1 }} numberOfLines={2}>
              {last.text}
            </Text>
          </View>
        ) : null}
        {!lookup && count > 0 ? <Button title={t('Done · {n} scanned', { n: count })} size="lg" icon="cart" onPress={() => router.back()} style={{ backgroundColor: c.primary }} /> : null}
      </View>
    </View>
  );
}

function Round({ icon, label, onPress }: { icon: React.ComponentProps<typeof Ionicons>['name']; label: string; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} accessibilityLabel={label} style={{ width: 46, height: 46, borderRadius: 23, backgroundColor: 'rgba(0,0,0,0.5)', alignItems: 'center', justifyContent: 'center' }}>
      <Ionicons name={icon} size={24} color="#fff" />
    </Pressable>
  );
}
