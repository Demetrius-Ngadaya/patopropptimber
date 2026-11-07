import React, { useEffect, useRef, useState } from 'react';
import { Animated, Easing, Pressable, View } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useSync } from '@/sync/manager';
import { useSession } from '@/lib/session';
import { ago } from '@/lib/format';
import { useT } from '@/i18n';
import { IconButton, ListRow, Sheet, Text, radius, space, useColors, type BadgeTone, type IconName } from '@/ui';
import type { NotificationCategory, OrderStatus, SaleSyncState } from '@/lib/types';

/** Online / offline / sending: always visible, tap for the sync centre. */
export function SyncPill({ onDark }: { onDark?: boolean }) {
  const t = useT();
  const c = useColors();
  const { running, online, waiting, attention } = useSync();
  const [spin] = useState(() => new Animated.Value(0));

  useEffect(() => {
    if (!running) return;
    const loop = Animated.loop(Animated.timing(spin, { toValue: 1, duration: 900, easing: Easing.linear, useNativeDriver: true }));
    loop.start();
    return () => {
      loop.stop();
      spin.setValue(0);
    };
  }, [running, spin]);

  const state = attention > 0 ? 'attention' : running ? 'syncing' : online === false ? 'offline' : waiting > 0 ? 'waiting' : 'ok';
  const meta = {
    attention: { icon: 'alert-circle' as const, color: c.danger, label: t('{n} to fix', { n: attention }) },
    syncing: { icon: 'sync' as const, color: onDark ? '#fff' : c.primary, label: t('Syncing') },
    offline: { icon: 'cloud-offline' as const, color: c.warning, label: waiting ? t('Offline · {n} waiting', { n: waiting }) : t('Offline') },
    waiting: { icon: 'cloud-upload' as const, color: c.warning, label: t('{n} waiting', { n: waiting }) },
    ok: { icon: 'cloud-done' as const, color: onDark ? '#BBF7D0' : c.accent, label: t('Synced') },
  }[state];
  const rotate = spin.interpolate({ inputRange: [0, 1], outputRange: ['0deg', '360deg'] });

  return (
    <Pressable
      onPress={() => router.push('/sync')}
      accessibilityLabel={meta.label}
      style={{ flexDirection: 'row', alignItems: 'center', gap: 5, height: 30, paddingHorizontal: 10, borderRadius: radius.pill, backgroundColor: onDark ? 'rgba(255,255,255,0.14)' : c.surface, borderWidth: onDark ? 0 : 1, borderColor: c.border }}
    >
      <Animated.View style={state === 'syncing' ? { transform: [{ rotate }] } : undefined}>
        <Ionicons name={meta.icon} size={15} color={meta.color} />
      </Animated.View>
      <Text variant="caption" style={{ color: onDark ? '#fff' : c.text, fontWeight: '700' }} numberOfLines={1}>
        {meta.label}
      </Text>
    </Pressable>
  );
}

/** Thin banner under a header when offline. */
export function OfflineBanner() {
  const t = useT();
  const c = useColors();
  const { online, lastOkAt } = useSync();
  if (online !== false) return null;
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: c.warningSoft, paddingVertical: 8, paddingHorizontal: space.lg }}>
      <Ionicons name="cloud-offline-outline" size={16} color={c.warning} />
      <Text variant="caption" style={{ color: c.warning, flex: 1 }}>
        {t('Offline. Keep working: everything syncs when the internet is back.')} {lastOkAt ? t('Last sync {when}.', { when: ago(lastOkAt, t) }) : ''}
      </Text>
    </View>
  );
}

/** Header for screens opened from a tab: back, title, actions. */
export function ScreenHeader({ title, subtitle, right, onBack }: { title: string; subtitle?: string; right?: React.ReactNode; onBack?: () => void }) {
  const t = useT();
  const c = useColors();
  const insets = useSafeAreaInsets();
  return (
    <View style={{ paddingTop: insets.top, backgroundColor: c.bg }}>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: space.xs, height: 56, paddingHorizontal: space.sm }}>
        <IconButton icon="chevron-back" label={t('Back')} onPress={onBack ?? (() => (router.canGoBack() ? router.back() : router.replace('/')))} />
        <View style={{ flex: 1 }}>
          <Text variant="heading" numberOfLines={1}>
            {title}
          </Text>
          {subtitle ? (
            <Text variant="caption" tone="muted" numberOfLines={1}>
              {subtitle}
            </Text>
          ) : null}
        </View>
        {right}
      </View>
      <OfflineBanner />
    </View>
  );
}

export function BranchSwitcher({ visible, onClose }: { visible: boolean; onClose: () => void }) {
  const t = useT();
  const { user, branchId, setBranch } = useSession();
  const branches = user?.all_branches ? user.branches : user?.branch ? [user.branch] : [];
  return (
    <Sheet visible={visible} onClose={onClose} title={t('Branch')}>
      <Text tone="muted" style={{ marginBottom: space.sm }}>
        {t('Stock, orders and sales on this phone follow the branch you choose.')}
      </Text>
      {branches.map((b) => (
        <ListRow
          key={b.id}
          title={b.name}
          subtitle={b.code}
          icon="storefront-outline"
          iconTone={b.id === branchId ? 'primary' : 'neutral'}
          right={b.id === branchId ? <Ionicons name="checkmark-circle" size={22} color="#16A34A" /> : undefined}
          chevron={false}
          onPress={async () => {
            onClose();
            await setBranch(b.id);
          }}
        />
      ))}
    </Sheet>
  );
}

export function useBranchName(): string {
  const { user, branchId } = useSession();
  const all = user?.branches ?? [];
  return all.find((b) => b.id === branchId)?.name ?? user?.branch?.name ?? '';
}

export const ORDER_STATUS: Record<OrderStatus, { label: string; tone: BadgeTone; icon: React.ComponentProps<typeof Ionicons>['name'] }> = {
  pending: { label: 'New', tone: 'warning', icon: 'sparkles' },
  confirmed: { label: 'Confirmed', tone: 'info', icon: 'checkmark-circle' },
  processing: { label: 'Preparing', tone: 'primary', icon: 'cube' },
  ready: { label: 'Ready', tone: 'success', icon: 'bag-check' },
  out_for_delivery: { label: 'On the way', tone: 'primary', icon: 'bicycle' },
  completed: { label: 'Completed', tone: 'success', icon: 'checkmark-done' },
  cancelled: { label: 'Cancelled', tone: 'danger', icon: 'close-circle' },
};

export const SALE_SYNC: Record<SaleSyncState, { label: string; tone: BadgeTone; icon: React.ComponentProps<typeof Ionicons>['name'] }> = {
  synced: { label: 'Synced', tone: 'success', icon: 'cloud-done' },
  pending: { label: 'Waiting to sync', tone: 'warning', icon: 'cloud-upload' },
  failed: { label: 'Will retry', tone: 'warning', icon: 'refresh' },
  attention: { label: 'Needs attention', tone: 'danger', icon: 'alert-circle' },
};

/** Counts up a number when it changes (dashboard totals). */
export function useCountUp(value: number, ms = 500): number {
  const [shown, setShown] = useState(value);
  const from = useRef(value);
  useEffect(() => {
    const start = Date.now();
    const begin = from.current;
    let frame: ReturnType<typeof requestAnimationFrame>;
    const step = () => {
      const p = Math.min(1, (Date.now() - start) / ms);
      const eased = 1 - (1 - p) ** 3;
      setShown(begin + (value - begin) * eased);
      if (p < 1) frame = requestAnimationFrame(step);
      else from.current = value;
    };
    frame = requestAnimationFrame(step);
    return () => cancelAnimationFrame(frame);
  }, [value, ms]);
  return shown;
}

export const CATEGORY_META: Record<NotificationCategory, { icon: IconName; tone: BadgeTone; label: string }> = {
  orders: { icon: 'receipt', tone: 'primary', label: 'Online orders' },
  stock: { icon: 'cube', tone: 'warning', label: 'Stock alerts' },
  money: { icon: 'cash', tone: 'success', label: 'Money and sales' },
  team: { icon: 'people', tone: 'info', label: 'Team and reminders' },
  general: { icon: 'notifications', tone: 'neutral', label: 'Other' },
};
