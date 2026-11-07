import React from 'react';
import { haptic as feel } from '../lib/haptics';
import { ActivityIndicator, Pressable, Text as RNText, View, type PressableProps, type StyleProp, type TextProps as RNTextProps, type TextStyle, type ViewStyle } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { radius, shadow, space, type as typo, useColors, type Colors } from './theme';

export type IconName = React.ComponentProps<typeof Ionicons>['name'];

type Variant = keyof typeof typo;
type Tone = 'default' | 'muted' | 'faint' | 'primary' | 'accent' | 'danger' | 'warning' | 'onPrimary';

export type TextProps = RNTextProps & { variant?: Variant; tone?: Tone; numeric?: boolean; center?: boolean };

function toneColor(c: Colors, tone: Tone): string {
  return {
    default: c.text,
    muted: c.textMuted,
    faint: c.textFaint,
    primary: c.primary,
    accent: c.accent,
    danger: c.danger,
    warning: c.warning,
    onPrimary: c.onPrimary,
  }[tone];
}

export function Text({ variant = 'body', tone = 'default', numeric, center, style, ...rest }: TextProps) {
  const c = useColors();
  return (
    <RNText
      maxFontSizeMultiplier={1.4}
      {...rest}
      style={[
        typo[variant],
        { color: toneColor(c, tone) },
        numeric && { fontVariant: ['tabular-nums'] },
        center && { textAlign: 'center' },
        style,
      ]}
    />
  );
}

export function Card({ children, style, padded = true, elevated = false }: { children: React.ReactNode; style?: StyleProp<ViewStyle>; padded?: boolean; elevated?: boolean }) {
  const c = useColors();
  return (
    <View
      style={[
        { backgroundColor: c.surface, borderRadius: radius.lg, borderWidth: 1, borderColor: c.border },
        padded && { padding: space.lg },
        elevated && shadow(c, 1),
        style,
      ]}
    >
      {children}
    </View>
  );
}

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'success';

export type ButtonProps = Omit<PressableProps, 'children' | 'style'> & {
  title: string;
  variant?: ButtonVariant;
  size?: 'sm' | 'md' | 'lg';
  icon?: IconName;
  iconRight?: IconName;
  loading?: boolean;
  full?: boolean;
  haptic?: boolean;
  style?: StyleProp<ViewStyle>;
};

export function Button({ title, variant = 'primary', size = 'md', icon, iconRight, loading, full, haptic = true, disabled, onPress, style, ...rest }: ButtonProps) {
  const c = useColors();
  const palette: Record<ButtonVariant, { bg: string; pressed: string; fg: string; border?: string }> = {
    primary: { bg: c.primary, pressed: c.primaryPressed, fg: c.onPrimary },
    success: { bg: c.accent, pressed: c.accent, fg: c.scheme === 'dark' ? '#06210F' : '#FFFFFF' },
    secondary: { bg: c.surface, pressed: c.surfacePressed, fg: c.text, border: c.borderStrong },
    ghost: { bg: 'transparent', pressed: c.surfacePressed, fg: c.primary },
    danger: { bg: c.dangerSoft, pressed: c.dangerSoft, fg: c.danger },
  };
  const p = palette[variant];
  const height = { sm: 36, md: 46, lg: 54 }[size];
  const off = disabled || loading;
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: !!off, busy: !!loading }}
      disabled={off}
      onPress={(e) => {
        if (haptic) feel.tap();
        onPress?.(e);
      }}
      style={({ pressed }) => [
        {
          height,
          paddingHorizontal: size === 'sm' ? space.md : space.lg,
          borderRadius: radius.md,
          backgroundColor: pressed ? p.pressed : p.bg,
          borderWidth: p.border ? 1 : 0,
          borderColor: p.border,
          flexDirection: 'row',
          alignItems: 'center',
          justifyContent: 'center',
          gap: space.sm,
          opacity: off && !loading ? 0.5 : 1,
          transform: [{ scale: pressed ? 0.98 : 1 }],
        },
        full && { alignSelf: 'stretch' },
        style,
      ]}
      {...rest}
    >
      {loading ? <ActivityIndicator color={p.fg} /> : icon ? <Ionicons name={icon} size={size === 'sm' ? 16 : 19} color={p.fg} /> : null}
      <Text variant={size === 'sm' ? 'label' : 'bodyStrong'} style={{ color: p.fg }} numberOfLines={1}>
        {title}
      </Text>
      {iconRight && !loading ? <Ionicons name={iconRight} size={18} color={p.fg} /> : null}
    </Pressable>
  );
}

export function IconButton({ icon, onPress, label, tone = 'default', size = 40, filled, badge, disabled }: { icon: IconName; onPress?: () => void; label: string; tone?: 'default' | 'primary' | 'onPrimary' | 'danger'; size?: number; filled?: boolean; badge?: number; disabled?: boolean }) {
  const c = useColors();
  const color = { default: c.text, primary: c.primary, onPrimary: c.onPrimary, danger: c.danger }[tone];
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      hitSlop={8}
      disabled={disabled}
      onPress={() => {
        feel.select();
        onPress?.();
      }}
      style={({ pressed }) => ({
        width: size,
        height: size,
        borderRadius: size / 2,
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: pressed ? (tone === 'onPrimary' ? 'rgba(255,255,255,0.18)' : c.surfacePressed) : filled ? (tone === 'onPrimary' ? 'rgba(255,255,255,0.12)' : c.surfaceAlt) : 'transparent',
        opacity: disabled ? 0.4 : 1,
      })}
    >
      <Ionicons name={icon} size={Math.round(size * 0.55)} color={color} />
      {badge ? (
        <View style={{ position: 'absolute', top: 2, right: 2, minWidth: 18, height: 18, borderRadius: 9, backgroundColor: c.danger, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 4, borderWidth: 2, borderColor: tone === 'onPrimary' ? c.primary : c.surface }}>
          <Text variant="tiny" style={{ color: '#fff', fontSize: 10, lineHeight: 12 }}>
            {badge > 99 ? '99+' : badge}
          </Text>
        </View>
      ) : null}
    </Pressable>
  );
}

export type BadgeTone = 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info';

export function Badge({ label, tone = 'neutral', icon, style }: { label: string; tone?: BadgeTone; icon?: IconName; style?: StyleProp<ViewStyle> }) {
  const c = useColors();
  const map: Record<BadgeTone, [string, string]> = {
    neutral: [c.surfaceAlt, c.textMuted],
    primary: [c.primarySoft, c.primary],
    success: [c.accentSoft, c.scheme === 'dark' ? c.accent : '#15803D'],
    warning: [c.warningSoft, c.warning],
    danger: [c.dangerSoft, c.danger],
    info: [c.infoSoft, c.info],
  };
  const [bg, fg] = map[tone];
  return (
    <View style={[{ flexDirection: 'row', alignItems: 'center', alignSelf: 'flex-start', gap: 4, backgroundColor: bg, borderRadius: radius.pill, paddingHorizontal: 8, paddingVertical: 3 }, style]}>
      {icon ? <Ionicons name={icon} size={12} color={fg} /> : null}
      <Text variant="caption" style={{ color: fg, fontWeight: '700' }} numberOfLines={1}>
        {label}
      </Text>
    </View>
  );
}

export function Chip({ label, selected, onPress, icon, count }: { label: string; selected?: boolean; onPress?: () => void; icon?: IconName; count?: number }) {
  const c = useColors();
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected }}
      onPress={() => {
        feel.select();
        onPress?.();
      }}
      style={({ pressed }) => ({
        flexDirection: 'row',
        alignItems: 'center',
        gap: 6,
        height: 34,
        paddingHorizontal: 14,
        borderRadius: radius.pill,
        backgroundColor: selected ? c.primary : pressed ? c.surfacePressed : c.surface,
        borderWidth: 1,
        borderColor: selected ? c.primary : c.border,
      })}
    >
      {icon ? <Ionicons name={icon} size={15} color={selected ? c.onPrimary : c.textMuted} /> : null}
      <Text variant="label" style={{ color: selected ? c.onPrimary : c.text }}>
        {label}
      </Text>
      {count !== undefined ? (
        <View style={{ minWidth: 20, paddingHorizontal: 5, height: 18, borderRadius: 9, alignItems: 'center', justifyContent: 'center', backgroundColor: selected ? 'rgba(255,255,255,0.22)' : c.surfaceAlt }}>
          <Text variant="tiny" style={{ color: selected ? c.onPrimary : c.textMuted }}>
            {count}
          </Text>
        </View>
      ) : null}
    </Pressable>
  );
}

export function Divider({ inset = 0, style }: { inset?: number; style?: StyleProp<ViewStyle> }) {
  const c = useColors();
  return <View style={[{ height: 1, backgroundColor: c.border, marginLeft: inset }, style]} />;
}

export function Icon({ name, size = 20, color, style }: { name: IconName; size?: number; color?: string; style?: StyleProp<TextStyle> }) {
  const c = useColors();
  return <Ionicons name={name} size={size} color={color ?? c.textMuted} style={style} />;
}

/** A soft rounded square holding an icon: list rows, stats, empty states. */
export function IconTile({ name, tone = 'primary', size = 40 }: { name: IconName; tone?: BadgeTone; size?: number }) {
  const c = useColors();
  const map: Record<BadgeTone, [string, string]> = {
    neutral: [c.surfaceAlt, c.textMuted],
    primary: [c.primarySoft, c.primary],
    success: [c.accentSoft, c.scheme === 'dark' ? c.accent : '#15803D'],
    warning: [c.warningSoft, c.warning],
    danger: [c.dangerSoft, c.danger],
    info: [c.infoSoft, c.info],
  };
  const [bg, fg] = map[tone];
  return (
    <View style={{ width: size, height: size, borderRadius: size * 0.3, backgroundColor: bg, alignItems: 'center', justifyContent: 'center' }}>
      <Ionicons name={name} size={size * 0.5} color={fg} />
    </View>
  );
}
