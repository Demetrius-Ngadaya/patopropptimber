import { useState } from 'react';
import { Image, Pressable, ScrollView, View, useWindowDimensions } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { money, qty } from '@/lib/format';
import { haptic } from '@/lib/haptics';
import { useLocale, useT } from '@/i18n';
import { Badge, Button, EmptyState, SkeletonList, Stepper, Text, radius, space, toast, useColors } from '@/ui';
import { ScreenHeader } from '@/features/common';
import { num, shopApi, useBasket, useRemote } from '@/features/shop/data';

/** One product: the whole picture (turnable), price, stock and "Add to basket". */
export default function Item() {
  const t = useT();
  const c = useColors();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const locale = useLocale((s) => s.locale);
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const branchId = useBasket((s) => s.branchId);
  const add = useBasket((s) => s.add);
  const inBasket = useBasket((s) => s.items.find((i) => i.slug === slug)?.quantity ?? 0);
  const product = useRemote(() => shopApi.product(String(slug), branchId), [slug, branchId]);
  const [count, setCount] = useState(1);
  const [turn, setTurn] = useState(0);
  const p = product.data;

  if (!p) {
    return (
      <View style={{ flex: 1, backgroundColor: c.bg }}>
        <ScreenHeader title={t('Product')} />
        {product.error ? (
          <EmptyState icon={product.error.offline ? 'cloud-offline-outline' : 'alert-circle-outline'} tone="warning" title={product.error.offline ? t('No internet') : t('Product not found')} text={product.error.message} action={{ title: t('Try again'), onPress: product.reload, icon: 'refresh' }} />
        ) : (
          <SkeletonList rows={4} />
        )}
      </View>
    );
  }

  const description = (locale === 'sw' && p.description_sw) || p.description;
  const size = Math.min(width, 520);
  const put = () => {
    add(p, count);
    haptic.success();
    toast.success(t('{name} added to the basket', { name: p.name }));
  };

  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <ScreenHeader title={p.name} subtitle={p.category?.name} />
      <ScrollView contentContainerStyle={{ paddingBottom: 140 + insets.bottom }}>
        <View style={{ width: size, height: size, alignSelf: 'center', backgroundColor: c.surface, alignItems: 'center', justifyContent: 'center', overflow: 'hidden' }}>
          {p.image_url ? (
            <Image source={{ uri: p.image_url }} style={{ width: size - 24, height: size - 24, transform: [{ rotate: `${turn * 90}deg` }] }} resizeMode="contain" />
          ) : (
            <Ionicons name="cube-outline" size={64} color={c.textFaint} />
          )}
          {p.image_url ? (
            <View style={{ position: 'absolute', right: space.md, bottom: space.md, flexDirection: 'row', gap: space.sm }}>
              {(['reload', 'refresh'] as const).map((icon, i) => (
                <Pressable key={icon} onPress={() => setTurn((n) => n + (i === 0 ? -1 : 1))} accessibilityLabel={i === 0 ? t('Turn left') : t('Turn right')} style={{ width: 40, height: 40, borderRadius: 20, backgroundColor: c.surface, borderWidth: 1, borderColor: c.border, alignItems: 'center', justifyContent: 'center', transform: i === 0 ? [{ scaleX: -1 }] : undefined }}>
                  <Ionicons name="refresh" size={20} color={c.text} />
                </Pressable>
              ))}
            </View>
          ) : null}
        </View>
        <View style={{ padding: space.lg, gap: space.md }}>
          <Text variant="title">{p.name}</Text>
          {p.brand ? (
            <Text tone="muted">{p.brand}</Text>
          ) : null}
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: space.md, flexWrap: 'wrap' }}>
            <Text variant="display" tone="primary" numeric>
              {money(num(p.selling_price))}
            </Text>
            {p.unit ? <Text tone="muted">/ {p.unit}</Text> : null}
          </View>
          {p.in_stock ? (
            <Badge tone="success" icon="checkmark-circle" label={p.available_quantity != null ? t('In stock · {n} available', { n: qty(p.available_quantity) }) : t('In stock')} />
          ) : (
            <Badge tone="danger" icon="close-circle" label={t('Out of stock')} />
          )}
          {description ? <Text style={{ marginTop: space.sm }}>{description}</Text> : null}
        </View>
      </ScrollView>

      <View style={{ position: 'absolute', left: 0, right: 0, bottom: 0, backgroundColor: c.surface, borderTopWidth: 1, borderTopColor: c.border, padding: space.lg, paddingBottom: insets.bottom + space.md, gap: space.sm }}>
        {inBasket ? (
          <Pressable onPress={() => router.push('/basket')}>
            <Text variant="caption" tone="primary" center>
              {t('{n} already in your basket · open basket', { n: qty(inBasket) })}
            </Text>
          </Pressable>
        ) : null}
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: space.md }}>
          <Stepper value={count} onChange={setCount} min={p.allow_decimal ? 0.25 : 1} decimals={p.allow_decimal} />
          <Button title={p.in_stock ? t('Add to basket') : t('Out of stock')} icon="basket-outline" disabled={!p.in_stock} onPress={put} style={{ flex: 1 }} />
        </View>
        <Text variant="caption" tone="faint" center style={{ borderRadius: radius.sm }}>
          {t('Total: {amount}', { amount: money(num(p.selling_price) * count) })}
        </Text>
      </View>
    </View>
  );
}
