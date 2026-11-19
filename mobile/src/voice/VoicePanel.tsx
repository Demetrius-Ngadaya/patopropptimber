import { useEffect } from 'react';
import { Modal, Pressable, ScrollView, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { usePathname } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import Animated, { Easing, useAnimatedStyle, useSharedValue, withRepeat, withTiming, cancelAnimation } from 'react-native-reanimated';
import { usePrefs } from '@/lib/prefs';
import { useT } from '@/i18n';
import { Button, Segmented, Text, radius, shadow, space, useColors } from '@/ui';
import { useVoice, waitingForYes } from './engine';

/**
 * Voice mode: a microphone button over the staff screens, and the panel that
 * listens, shows what was heard and what happened (and says it out loud).
 */
export function VoiceButton() {
  const t = useT();
  const c = useColors();
  const insets = useSafeAreaInsets();
  const enabled = usePrefs((s) => s.voice.enabled);
  const show = useVoice((s) => s.show);
  const path = usePathname();
  // Above the tab bar on the tab screens, near the bottom edge elsewhere.
  const onTabs = ['/', '/sell', '/orders', '/products', '/more'].includes(path);
  if (!enabled) return null;
  return (
    <>
      <Pressable
        onPress={show}
        accessibilityRole="button"
        accessibilityLabel={t('Voice mode: tap and speak')}
        style={({ pressed }) => [
          { position: 'absolute', right: space.lg, bottom: (onTabs ? 76 : 20) + Math.max(insets.bottom, 8), width: 58, height: 58, borderRadius: 29, backgroundColor: pressed ? c.primaryPressed : c.primary, alignItems: 'center', justifyContent: 'center', zIndex: 50 },
          shadow(c, 3),
        ]}
      >
        <Ionicons name="mic" size={28} color={c.onPrimary} />
      </Pressable>
      <VoicePanel />
    </>
  );
}

function VoicePanel() {
  const t = useT();
  const c = useColors();
  const insets = useSafeAreaInsets();
  const { open, phase, partial, heard, reply, choices, log, lang, setLang, hide, listen, stop, handle, choose } = useVoice();
  const pulse = useSharedValue(0);

  useEffect(() => {
    if (phase === 'listening') pulse.value = withRepeat(withTiming(1, { duration: 900, easing: Easing.out(Easing.quad) }), -1, false);
    else {
      cancelAnimation(pulse);
      pulse.value = 0;
    }
  }, [phase, pulse]);
  const ring = useAnimatedStyle(() => ({ opacity: phase === 'listening' ? 0.45 * (1 - pulse.value) : 0, transform: [{ scale: 1 + pulse.value * 0.8 }] }));

  const yes = waitingForYes();
  const status =
    phase === 'listening' ? t('Listening… speak now') : phase === 'thinking' ? t('Working on it…') : phase === 'speaking' ? t('Answering…') : t('Tap the microphone and speak');

  return (
    <Modal visible={open} transparent animationType="slide" onRequestClose={hide} statusBarTranslucent>
      <Pressable style={{ flex: 1, backgroundColor: 'rgba(5,10,20,0.45)' }} onPress={hide} />
      <View style={{ backgroundColor: c.surface, borderTopLeftRadius: radius.xl, borderTopRightRadius: radius.xl, padding: space.lg, paddingBottom: insets.bottom + space.lg, gap: space.md, maxHeight: '82%' }}>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: space.md }}>
          <Text variant="heading" style={{ flex: 1 }}>
            {t('Voice mode')}
          </Text>
          <View style={{ width: 190 }}>
            <Segmented value={lang} onChange={setLang} options={[{ value: 'sw', label: 'Kiswahili' }, { value: 'en', label: 'English' }]} />
          </View>
          <Pressable onPress={hide} hitSlop={10} accessibilityLabel={t('Close')}>
            <Ionicons name="close" size={24} color={c.textMuted} />
          </Pressable>
        </View>

        <View style={{ alignItems: 'center', paddingVertical: space.md, gap: space.sm }}>
          <View style={{ width: 96, height: 96, alignItems: 'center', justifyContent: 'center' }}>
            <Animated.View style={[{ position: 'absolute', width: 96, height: 96, borderRadius: 48, backgroundColor: c.primary }, ring]} />
            <Pressable
              onPress={() => (phase === 'listening' || phase === 'speaking' ? stop() : listen())}
              accessibilityRole="button"
              accessibilityLabel={phase === 'listening' ? t('Stop listening') : t('Speak')}
              style={({ pressed }) => ({ width: 80, height: 80, borderRadius: 40, backgroundColor: phase === 'listening' ? c.danger : pressed ? c.primaryPressed : c.primary, alignItems: 'center', justifyContent: 'center' })}
            >
              <Ionicons name={phase === 'listening' ? 'stop' : phase === 'thinking' ? 'hourglass-outline' : 'mic'} size={36} color={c.onPrimary} />
            </Pressable>
          </View>
          <Text variant="label" tone="muted">
            {status}
          </Text>
          {partial || heard ? (
            <Text variant="bodyStrong" center style={{ fontStyle: 'italic' }}>
              “{partial || heard}”
            </Text>
          ) : null}
        </View>

        {reply ? (
          <View style={{ flexDirection: 'row', gap: space.sm, padding: space.md, borderRadius: radius.lg, backgroundColor: reply.ok ? c.primarySoft : c.dangerSoft }}>
            <Ionicons name={reply.ok ? 'checkmark-circle' : 'alert-circle'} size={22} color={reply.ok ? c.primary : c.danger} />
            <Text style={{ flex: 1 }}>{reply.text}</Text>
          </View>
        ) : null}

        {choices.length ? (
          <View style={{ gap: space.sm }}>
            {choices.map((label, i) => (
              <Button key={label + i} title={`${i + 1}. ${label}`} variant="secondary" onPress={() => choose(i)} />
            ))}
          </View>
        ) : null}
        {yes ? (
          <View style={{ flexDirection: 'row', gap: space.md }}>
            <Button title={lang === 'sw' ? 'Ndiyo' : 'Yes'} icon="checkmark" style={{ flex: 1 }} onPress={() => handle(lang === 'sw' ? 'ndiyo' : 'yes')} />
            <Button title={lang === 'sw' ? 'Hapana' : 'No'} icon="close" variant="secondary" style={{ flex: 1 }} onPress={() => handle(lang === 'sw' ? 'hapana' : 'no')} />
          </View>
        ) : null}

        {log.length > 1 ? (
          <ScrollView style={{ maxHeight: 150 }} contentContainerStyle={{ gap: 6 }}>
            {log.slice(1, 8).map((l) => (
              <Text key={l.id} variant="caption" tone="faint" numberOfLines={2}>
                {l.reply.ok ? '✓' : '✗'} “{l.heard}” — {l.reply.text}
              </Text>
            ))}
          </ScrollView>
        ) : null}

        <Button title={t('What can I say?')} variant="ghost" size="sm" icon="help-circle-outline" onPress={() => handle(lang === 'sw' ? 'msaada' : 'help')} />
      </View>
    </Modal>
  );
}
