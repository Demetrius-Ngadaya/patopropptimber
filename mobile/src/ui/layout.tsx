import React from 'react';
import { haptic } from '../lib/haptics';
import { Pressable, RefreshControl, ScrollView, View, type ScrollViewProps, type StyleProp, type ViewStyle } from 'react-native';
import { SafeAreaView, type Edge } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { Card, IconTile, Text, type BadgeTone, type IconName } from './primitives';
import { radius, space, useColors } from './theme';

export function Screen({ children, scroll = false, edges = ['top'], padded = true, refreshing, onRefresh, contentStyle, footer, ...scrollProps }: {
  children: React.ReactNode;
  scroll?: boolean;
  edges?: Edge[];
  padded?: boolean;
  refreshing?: boolean;
  onRefresh?: () => void;
  contentStyle?: StyleProp<ViewStyle>;
  footer?: React.ReactNode;
} & ScrollViewProps) {
  const c = useColors();
  const pad = padded ? { paddingHorizontal: space.lg } : null;
  return (
    <SafeAreaView edges={edges} style={{ flex: 1, backgroundColor: c.bg }}>
      {scroll ? (
        <ScrollView
          keyboardShouldPersistTaps="handled"
          contentContainerStyle={[pad, { paddingBottom: space.xxxl }, contentStyle]}
          refreshControl={onRefresh ? <RefreshControl refreshing={!!refreshing} onRefresh={onRefresh} tintColor={c.primary} colors={[c.primary]} /> : undefined}
          {...scrollProps}
        >
          {children}
        </ScrollView>
      ) : (
        <View style={[{ flex: 1 }, pad, contentStyle]}>{children}</View>
      )}
      {footer}
    </SafeAreaView>
  );
}

export function Header({ title, subtitle, right, left }: { title: string; subtitle?: string; right?: React.ReactNode; left?: React.ReactNode }) {
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: space.sm, paddingTop: space.md, paddingBottom: space.md }}>
      {left}
      <View style={{ flex: 1 }}>
        <Text variant="title" numberOfLines={1}>
          {title}
        </Text>
        {subtitle ? (
          <Text variant="caption" tone="muted" numberOfLines={1}>
            {subtitle}
          </Text>
        ) : null}
      </View>
      {right ? <View style={{ flexDirection: 'row', alignItems: 'center', gap: 2 }}>{right}</View> : null}
    </View>
  );
}

export function Section({ title, action, children, style }: { title?: string; action?: { label: string; onPress: () => void }; children: React.ReactNode; style?: StyleProp<ViewStyle> }) {
  return (
    <View style={[{ marginTop: space.xl, gap: space.sm }, style]}>
      {title ? (
        <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
          <Text variant="tiny" tone="muted" style={{ textTransform: 'uppercase' }}>
            {title}
          </Text>
          {action ? (
            <Pressable onPress={action.onPress} hitSlop={10}>
              <Text variant="label" tone="primary">
                {action.label}
              </Text>
            </Pressable>
          ) : null}
        </View>
      ) : null}
      {children}
    </View>
  );
}

export function ListRow({ title, subtitle, meta, right, icon, iconTone, onPress, chevron = !!onPress, leading, numberOfLines = 1, danger }: {
  title: string;
  subtitle?: string | null;
  meta?: React.ReactNode;
  right?: React.ReactNode;
  icon?: IconName;
  iconTone?: BadgeTone;
  leading?: React.ReactNode;
  onPress?: () => void;
  chevron?: boolean;
  numberOfLines?: number;
  danger?: boolean;
}) {
  const c = useColors();
  return (
    <Pressable
      disabled={!onPress}
      onPress={() => {
        haptic.select();
        onPress?.();
      }}
      style={({ pressed }) => ({ flexDirection: 'row', alignItems: 'center', gap: space.md, paddingVertical: space.md, paddingHorizontal: space.lg, backgroundColor: pressed ? c.surfacePressed : 'transparent' })}
    >
      {leading ?? (icon ? <IconTile name={icon} tone={iconTone ?? 'primary'} size={40} /> : null)}
      <View style={{ flex: 1, gap: 2 }}>
        <Text variant="bodyStrong" numberOfLines={numberOfLines} tone={danger ? 'danger' : 'default'}>
          {title}
        </Text>
        {subtitle ? (
          <Text variant="caption" tone="muted" numberOfLines={2}>
            {subtitle}
          </Text>
        ) : null}
        {meta}
      </View>
      {right}
      {chevron ? <Ionicons name="chevron-forward" size={18} color={c.textFaint} /> : null}
    </Pressable>
  );
}

/** A card holding rows separated by hairlines (iOS grouped list look). */
export function Group({ children, style }: { children: React.ReactNode; style?: StyleProp<ViewStyle> }) {
  const c = useColors();
  const items = React.Children.toArray(children).filter(Boolean);
  return (
    <Card padded={false} style={[{ overflow: 'hidden' }, style]}>
      {items.map((child, i) => (
        <View key={i}>
          {i > 0 ? <View style={{ height: 1, backgroundColor: c.border, marginLeft: space.lg }} /> : null}
          {child}
        </View>
      ))}
    </Card>
  );
}

export function StatTile({ label, value, icon, tone = 'primary', hint, onPress, style }: { label: string; value: string; icon: IconName; tone?: BadgeTone; hint?: string; onPress?: () => void; style?: StyleProp<ViewStyle> }) {
  const c = useColors();
  return (
    <Pressable disabled={!onPress} onPress={onPress} style={({ pressed }) => [{ flex: 1, borderRadius: radius.lg, backgroundColor: pressed ? c.surfacePressed : c.surface, borderWidth: 1, borderColor: c.border, padding: space.md, gap: space.sm }, style]}>
      <IconTile name={icon} tone={tone} size={34} />
      <Text variant="heading" numeric numberOfLines={1} adjustsFontSizeToFit>
        {value}
      </Text>
      <Text variant="caption" tone="muted" numberOfLines={1}>
        {label}
      </Text>
      {hint ? (
        <Text variant="tiny" tone="faint" numberOfLines={1}>
          {hint}
        </Text>
      ) : null}
    </Pressable>
  );
}
