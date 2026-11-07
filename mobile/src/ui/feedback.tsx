import React, { useEffect, useState } from 'react';
import { Animated, Easing, KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, View, type DimensionValue, type StyleProp, type ViewStyle } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { create } from 'zustand';
import { Button, IconTile, Text, type BadgeTone, type IconName } from './primitives';
import { radius, shadow, space, useColors } from './theme';
import { t } from '../i18n';

// ------------------------------------------------------------------ skeleton

/** Grey placeholder that gently pulses while real data loads (no spinners). */
export function Skeleton({ width = '100%', height = 14, round = radius.sm, style }: { width?: DimensionValue; height?: number; round?: number; style?: StyleProp<ViewStyle> }) {
  const c = useColors();
  const [pulse] = useState(() => new Animated.Value(0.5));
  useEffect(() => {
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(pulse, { toValue: 1, duration: 700, easing: Easing.inOut(Easing.quad), useNativeDriver: true }),
        Animated.timing(pulse, { toValue: 0.5, duration: 700, easing: Easing.inOut(Easing.quad), useNativeDriver: true }),
      ]),
    );
    loop.start();
    return () => loop.stop();
  }, [pulse]);
  return <Animated.View style={[{ width, height, borderRadius: round, backgroundColor: c.surfaceAlt, opacity: pulse }, style]} />;
}

export function SkeletonList({ rows = 6, avatar = true }: { rows?: number; avatar?: boolean }) {
  return (
    <View style={{ gap: space.lg, padding: space.lg }}>
      {Array.from({ length: rows }, (_, i) => (
        <View key={i} style={{ flexDirection: 'row', alignItems: 'center', gap: space.md }}>
          {avatar ? <Skeleton width={42} height={42} round={12} /> : null}
          <View style={{ flex: 1, gap: 8 }}>
            <Skeleton width={`${70 - ((i * 13) % 30)}%`} />
            <Skeleton width="40%" height={11} />
          </View>
          <Skeleton width={64} height={16} />
        </View>
      ))}
    </View>
  );
}

// --------------------------------------------------------------- empty state

export function EmptyState({ icon, title, text, action, tone = 'primary' }: { icon: IconName; title: string; text?: string; action?: { title: string; onPress: () => void; icon?: IconName }; tone?: BadgeTone }) {
  return (
    <View style={{ alignItems: 'center', paddingHorizontal: space.xxl, paddingVertical: space.xxxl, gap: space.md }}>
      <IconTile name={icon} tone={tone} size={64} />
      <Text variant="heading" center>
        {title}
      </Text>
      {text ? (
        <Text tone="muted" center>
          {text}
        </Text>
      ) : null}
      {action ? <Button title={action.title} icon={action.icon} onPress={action.onPress} style={{ marginTop: space.sm }} /> : null}
    </View>
  );
}

// --------------------------------------------------------------------- toast

type ToastKind = 'success' | 'error' | 'info' | 'warning';
type ToastState = { message: string | null; kind: ToastKind; key: number; show: (message: string, kind?: ToastKind) => void; hide: () => void };

export const useToast = create<ToastState>((set) => ({
  message: null,
  kind: 'info',
  key: 0,
  show: (message, kind = 'info') => set((s) => ({ message, kind, key: s.key + 1 })),
  hide: () => set({ message: null }),
}));

export const toast = {
  success: (m: string) => useToast.getState().show(m, 'success'),
  error: (m: string) => useToast.getState().show(m, 'error'),
  info: (m: string) => useToast.getState().show(m, 'info'),
  warning: (m: string) => useToast.getState().show(m, 'warning'),
};

export function ToastHost() {
  const c = useColors();
  const insets = useSafeAreaInsets();
  const { message, kind, key, hide } = useToast();
  const [y] = useState(() => new Animated.Value(-120));

  useEffect(() => {
    if (!message) return;
    Animated.spring(y, { toValue: 0, useNativeDriver: true, damping: 18, stiffness: 180 }).start();
    const timer = setTimeout(() => {
      Animated.timing(y, { toValue: -120, duration: 220, useNativeDriver: true }).start(() => hide());
    }, kind === 'error' ? 4500 : 2800);
    return () => clearTimeout(timer);
  }, [key, message, kind, hide, y]);

  if (!message) return null;
  const meta: Record<ToastKind, [IconName, string]> = {
    success: ['checkmark-circle', c.accent],
    error: ['alert-circle', c.danger],
    info: ['information-circle', c.info],
    warning: ['warning', c.warning],
  };
  const [icon, color] = meta[kind];
  return (
    <Animated.View pointerEvents="box-none" style={{ position: 'absolute', top: insets.top + 8, left: space.lg, right: space.lg, transform: [{ translateY: y }], zIndex: 1000 }}>
      <Pressable onPress={hide} accessibilityRole="alert" style={[{ flexDirection: 'row', alignItems: 'center', gap: space.md, backgroundColor: c.scheme === 'dark' ? c.surfaceAlt : '#0E1726', borderRadius: radius.lg, padding: space.md, paddingRight: space.lg }, shadow(c, 3)]}>
        <Ionicons name={icon} size={22} color={color} />
        <Text variant="bodyStrong" style={{ color: '#FFFFFF', flex: 1 }}>
          {message}
        </Text>
      </Pressable>
    </Animated.View>
  );
}

// --------------------------------------------------------------------- sheet

/** Bottom sheet for pickers and short forms. */
export function Sheet({ visible, onClose, title, children, footer, height }: { visible: boolean; onClose: () => void; title?: string; children: React.ReactNode; footer?: React.ReactNode; height?: DimensionValue }) {
  const c = useColors();
  const insets = useSafeAreaInsets();
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose} statusBarTranslucent>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1, justifyContent: 'flex-end' }}>
        <Pressable style={{ position: 'absolute', top: 0, bottom: 0, left: 0, right: 0, backgroundColor: c.overlay }} onPress={onClose} accessibilityLabel="Close" />
        <View style={{ backgroundColor: c.surface, borderTopLeftRadius: radius.xl, borderTopRightRadius: radius.xl, maxHeight: '90%', height, paddingBottom: insets.bottom + space.md }}>
          <View style={{ alignItems: 'center', paddingTop: space.sm }}>
            <View style={{ width: 40, height: 5, borderRadius: 3, backgroundColor: c.borderStrong }} />
          </View>
          {title ? (
            <View style={{ flexDirection: 'row', alignItems: 'center', paddingHorizontal: space.lg, paddingTop: space.md, paddingBottom: space.sm }}>
              <Text variant="heading" style={{ flex: 1 }}>
                {title}
              </Text>
              <Pressable onPress={onClose} hitSlop={12} accessibilityLabel="Close">
                <Ionicons name="close" size={24} color={c.textMuted} />
              </Pressable>
            </View>
          ) : null}
          <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ paddingHorizontal: space.lg, paddingBottom: space.md }}>
            {children}
          </ScrollView>
          {footer ? <View style={{ paddingHorizontal: space.lg, paddingTop: space.sm }}>{footer}</View> : null}
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
}

// --------------------------------------------------------------- confirm box

type ConfirmState = {
  open: boolean;
  title: string;
  message?: string;
  confirm: string;
  cancel: string;
  danger: boolean;
  resolve?: (ok: boolean) => void;
};

const useConfirm = create<ConfirmState>(() => ({ open: false, title: '', confirm: 'OK', cancel: 'Cancel', danger: false }));

/** await confirm({...}) → true / false, styled like the app (not the system alert). */
export function confirm(o: { title: string; message?: string; confirm?: string; cancel?: string; danger?: boolean }): Promise<boolean> {
  return new Promise((resolve) => {
    useConfirm.setState({ open: true, title: o.title, message: o.message, confirm: o.confirm ?? t('OK'), cancel: o.cancel ?? t('Cancel'), danger: !!o.danger, resolve });
  });
}

export function ConfirmHost() {
  const c = useColors();
  const s = useConfirm();
  const close = (ok: boolean) => {
    s.resolve?.(ok);
    useConfirm.setState({ open: false, resolve: undefined });
  };
  return (
    <Modal visible={s.open} transparent animationType="fade" onRequestClose={() => close(false)} statusBarTranslucent>
      <View style={{ flex: 1, backgroundColor: c.overlay, alignItems: 'center', justifyContent: 'center', padding: space.xxl }}>
        <View style={[{ width: '100%', maxWidth: 380, backgroundColor: c.surface, borderRadius: radius.xl, padding: space.xl, gap: space.md }, shadow(c, 3)]}>
          <Text variant="heading">{s.title}</Text>
          {s.message ? <Text tone="muted">{s.message}</Text> : null}
          <View style={{ flexDirection: 'row', gap: space.sm, marginTop: space.sm }}>
            <Button title={s.cancel} variant="secondary" style={{ flex: 1 }} onPress={() => close(false)} />
            <Button title={s.confirm} variant={s.danger ? 'danger' : 'primary'} style={{ flex: 1 }} onPress={() => close(true)} />
          </View>
        </View>
      </View>
    </Modal>
  );
}
