import React, { forwardRef, useState } from 'react';
import { haptic } from '../lib/haptics';
import { Pressable, Switch, TextInput, View, type StyleProp, type TextInputProps, type ViewStyle } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { cleanNumber } from '../lib/format';
import { Text, type IconName } from './primitives';
import { radius, space, useColors } from './theme';

export type InputProps = TextInputProps & {
  label?: string;
  hint?: string;
  error?: string | null;
  icon?: IconName;
  right?: React.ReactNode;
  containerStyle?: StyleProp<ViewStyle>;
};

export const Input = forwardRef<React.ComponentRef<typeof TextInput>, InputProps>(function Input({ label, hint, error, icon, right, containerStyle, style, onFocus, onBlur, ...rest }, ref) {
  const c = useColors();
  const [focused, setFocused] = useState(false);
  return (
    <View style={[{ gap: 6 }, containerStyle]}>
      {label ? (
        <Text variant="label" tone="muted">
          {label}
        </Text>
      ) : null}
      <View
        style={{
          flexDirection: 'row',
          alignItems: 'center',
          minHeight: 48,
          borderRadius: radius.md,
          borderWidth: focused ? 2 : 1,
          borderColor: error ? c.danger : focused ? c.primary : c.border,
          backgroundColor: c.surface,
          paddingHorizontal: focused ? space.md - 1 : space.md,
          gap: space.sm,
        }}
      >
        {icon ? <Ionicons name={icon} size={18} color={c.textFaint} /> : null}
        <TextInput
          ref={ref}
          placeholderTextColor={c.textFaint}
          selectionColor={c.primary}
          maxFontSizeMultiplier={1.4}
          accessibilityLabel={label}
          accessibilityHint={hint}
          onFocus={(e) => {
            setFocused(true);
            onFocus?.(e);
          }}
          onBlur={(e) => {
            setFocused(false);
            onBlur?.(e);
          }}
          style={[{ flex: 1, color: c.text, fontSize: 16, paddingVertical: 10 }, style]}
          {...rest}
        />
        {right}
      </View>
      {error ? (
        <Text variant="caption" tone="danger">
          {error}
        </Text>
      ) : hint ? (
        <Text variant="caption" tone="faint">
          {hint}
        </Text>
      ) : null}
    </View>
  );
});

/**
 * Numbers only, no spinner arrows: the number pad, commas become dots, and
 * a half-typed "12." survives while typing.
 */
export function NumberInput({ value, onChange, decimals = true, ...rest }: Omit<InputProps, 'value' | 'onChangeText' | 'onChange'> & { value: number | null; onChange: (n: number | null) => void; decimals?: boolean }) {
  const [draft, setDraft] = useState(value === null ? '' : String(value));
  const [seen, setSeen] = useState(value);
  if (value !== seen) {
    // Changed from outside (cleared, filled in): show it, unless it is what is typed already.
    setSeen(value);
    const parsed = draft === '' || draft === '.' ? null : Number(draft);
    if (parsed !== value) setDraft(value === null ? '' : String(value));
  }
  return (
    <Input
      {...rest}
      value={draft}
      keyboardType={decimals ? 'decimal-pad' : 'number-pad'}
      inputMode={decimals ? 'decimal' : 'numeric'}
      onChangeText={(raw) => {
        const clean = cleanNumber(raw, decimals);
        setDraft(clean);
        onChange(clean === '' || clean === '.' ? null : Number(clean));
      }}
    />
  );
}

export function SearchBar({ value, onChangeText, placeholder, onScan, autoFocus }: { value: string; onChangeText: (s: string) => void; placeholder: string; onScan?: () => void; autoFocus?: boolean }) {
  const c = useColors();
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', height: 46, borderRadius: radius.md, backgroundColor: c.surfaceAlt, paddingHorizontal: space.md, gap: space.sm }}>
      <Ionicons name="search" size={18} color={c.textFaint} />
      <TextInput
        value={value}
        onChangeText={onChangeText}
        placeholder={placeholder}
        placeholderTextColor={c.textFaint}
        autoFocus={autoFocus}
        autoCorrect={false}
        autoCapitalize="none"
        returnKeyType="search"
        clearButtonMode="while-editing"
        maxFontSizeMultiplier={1.3}
        style={{ flex: 1, color: c.text, fontSize: 16 }}
      />
      {value ? (
        <Pressable hitSlop={10} onPress={() => onChangeText('')} accessibilityLabel="Clear">
          <Ionicons name="close-circle" size={18} color={c.textFaint} />
        </Pressable>
      ) : null}
      {onScan ? (
        <Pressable hitSlop={10} onPress={onScan} accessibilityLabel="Scan barcode" style={{ paddingLeft: 4 }}>
          <Ionicons name="barcode-outline" size={22} color={c.primary} />
        </Pressable>
      ) : null}
    </View>
  );
}

export function Segmented<T extends string>({ value, options, onChange }: { value: T; options: { value: T; label: string }[]; onChange: (v: T) => void }) {
  const c = useColors();
  return (
    <View style={{ flexDirection: 'row', backgroundColor: c.surfaceAlt, borderRadius: radius.md, padding: 3 }}>
      {options.map((o) => {
        const on = o.value === value;
        return (
          <Pressable
            key={o.value}
            accessibilityRole="button"
            accessibilityState={{ selected: on }}
            onPress={() => {
              haptic.select();
              onChange(o.value);
            }}
            style={{ flex: 1, height: 36, borderRadius: radius.sm + 2, alignItems: 'center', justifyContent: 'center', backgroundColor: on ? c.surface : 'transparent' }}
          >
            <Text variant="label" style={{ color: on ? c.text : c.textMuted }} numberOfLines={1}>
              {o.label}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

export function SwitchRow({ title, subtitle, value, onChange, icon, disabled }: { title: string; subtitle?: string; value: boolean; onChange: (v: boolean) => void; icon?: IconName; disabled?: boolean }) {
  const c = useColors();
  return (
    <Pressable
      disabled={disabled}
      onPress={() => {
        haptic.select();
        onChange(!value);
      }}
      style={{ flexDirection: 'row', alignItems: 'center', gap: space.md, paddingVertical: space.md, opacity: disabled ? 0.5 : 1 }}
      accessibilityRole="switch"
      accessibilityState={{ checked: value, disabled }}
    >
      {icon ? <Ionicons name={icon} size={20} color={value ? c.primary : c.textFaint} /> : null}
      <View style={{ flex: 1, gap: 2 }}>
        <Text variant="bodyStrong">{title}</Text>
        {subtitle ? (
          <Text variant="caption" tone="muted">
            {subtitle}
          </Text>
        ) : null}
      </View>
      <Switch
        value={value}
        disabled={disabled}
        onValueChange={onChange}
        trackColor={{ false: c.borderStrong, true: c.primary }}
        thumbColor="#FFFFFF"
        ios_backgroundColor={c.borderStrong}
      />
    </Pressable>
  );
}

/** − 3 + : tap to step, or type the quantity. */
export function Stepper({ value, onChange, min = 0, max, decimals = false, compact }: { value: number; onChange: (n: number) => void; min?: number; max?: number; decimals?: boolean; compact?: boolean }) {
  const c = useColors();
  const [draft, setDraft] = useState(String(value));
  const [seen, setSeen] = useState(value);
  if (value !== seen) {
    setSeen(value);
    setDraft(String(value));
  }
  const set = (n: number) => {
    const clamped = Math.max(min, max !== undefined ? Math.min(max, n) : n);
    haptic.select();
    onChange(decimals ? Math.round(clamped * 1000) / 1000 : Math.round(clamped));
  };
  const size = compact ? 32 : 38;
  const btn = (icon: IconName, delta: number, label: string) => (
    <Pressable
      accessibilityLabel={label}
      onPress={() => set(value + delta)}
      hitSlop={6}
      style={({ pressed }) => ({ width: size, height: size, borderRadius: radius.sm + 2, alignItems: 'center', justifyContent: 'center', backgroundColor: pressed ? c.surfacePressed : c.surfaceAlt })}
    >
      <Ionicons name={icon} size={18} color={c.text} />
    </Pressable>
  );
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
      {btn('remove', -1, 'Less')}
      <TextInput
        value={draft}
        keyboardType={decimals ? 'decimal-pad' : 'number-pad'}
        selectTextOnFocus
        onChangeText={(raw) => setDraft(cleanNumber(raw, decimals))}
        onEndEditing={() => {
          const n = Number(draft);
          if (draft === '' || Number.isNaN(n)) setDraft(String(value));
          else set(n);
        }}
        style={{ width: compact ? 46 : 56, height: size, textAlign: 'center', borderRadius: radius.sm + 2, borderWidth: 1, borderColor: c.border, color: c.text, fontSize: 16, fontWeight: '700', fontVariant: ['tabular-nums'], backgroundColor: c.surface }}
        maxFontSizeMultiplier={1.2}
      />
      {btn('add', 1, 'More')}
    </View>
  );
}
