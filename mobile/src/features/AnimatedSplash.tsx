import { useEffect } from 'react';
import { Image, StyleSheet, View } from 'react-native';
import Animated, { Easing, runOnJS, useAnimatedStyle, useSharedValue, withDelay, withRepeat, withSequence, withSpring, withTiming } from 'react-native-reanimated';
import { Text, useColors } from '@/ui';

/**
 * The opening animation, right after the phone's own splash (same logo, same
 * background, so the hand-over is seamless): the logo springs in with a small
 * turn, two rings ripple out, "PatoPro" rises in, then everything fades away
 * as soon as the app is ready (never shorter than ~1.4 s, so it is seen whole).
 */
export function AnimatedSplash({ ready, onDone }: { ready: boolean; onDone: () => void }) {
  const c = useColors();
  const logo = useSharedValue(0);
  const ring = useSharedValue(0);
  const words = useSharedValue(0);
  const tag = useSharedValue(0);
  const shine = useSharedValue(0);
  const out = useSharedValue(1);
  const minTime = useSharedValue(0);

  useEffect(() => {
    logo.value = withSpring(1, { damping: 9, stiffness: 120, mass: 0.8 });
    ring.value = withDelay(250, withRepeat(withTiming(1, { duration: 1300, easing: Easing.out(Easing.cubic) }), -1, false));
    words.value = withDelay(380, withSpring(1, { damping: 14, stiffness: 140 }));
    tag.value = withDelay(650, withTiming(1, { duration: 450 }));
    shine.value = withDelay(700, withRepeat(withSequence(withTiming(1, { duration: 900, easing: Easing.inOut(Easing.quad) }), withTiming(0, { duration: 0 })), -1, false));
    minTime.value = withTiming(1, { duration: 1400 });
  }, [logo, ring, words, tag, shine, minTime]);

  useEffect(() => {
    if (!ready) return;
    const wait = setTimeout(() => {
      out.value = withTiming(0, { duration: 380, easing: Easing.in(Easing.quad) }, (finished) => {
        if (finished) runOnJS(onDone)();
      });
    }, Math.max(0, (1 - minTime.value) * 1400));
    return () => clearTimeout(wait);
  }, [ready, onDone, out, minTime]);

  const logoStyle = useAnimatedStyle(() => ({
    opacity: Math.min(1, logo.value * 1.4),
    transform: [{ scale: 0.55 + logo.value * 0.45 }, { rotate: `${(1 - logo.value) * -18}deg` }],
  }));
  const ring1 = useAnimatedStyle(() => ({ opacity: (1 - ring.value) * 0.35, transform: [{ scale: 0.9 + ring.value * 1.5 }] }));
  const ring2 = useAnimatedStyle(() => {
    const p = (ring.value + 0.5) % 1;
    return { opacity: (1 - p) * 0.35, transform: [{ scale: 0.9 + p * 1.5 }] };
  });
  const wordsStyle = useAnimatedStyle(() => ({ opacity: words.value, transform: [{ translateY: (1 - words.value) * 22 }] }));
  const tagStyle = useAnimatedStyle(() => ({ opacity: tag.value, letterSpacing: 2 + tag.value * 2 }));
  const shineStyle = useAnimatedStyle(() => ({ transform: [{ translateX: -120 + shine.value * 240 }, { rotate: '20deg' }], opacity: shine.value > 0 && shine.value < 1 ? 0.55 : 0 }));
  const outStyle = useAnimatedStyle(() => ({ opacity: out.value, transform: [{ scale: 1 + (1 - out.value) * 0.06 }] }));

  return (
    <Animated.View pointerEvents="none" style={[StyleSheet.absoluteFill, { backgroundColor: c.bg, alignItems: 'center', justifyContent: 'center', zIndex: 1000 }, outStyle]}>
      <View style={{ width: 150, height: 150, alignItems: 'center', justifyContent: 'center' }}>
        <Animated.View style={[styles.ring, { borderColor: c.primary }, ring1]} />
        <Animated.View style={[styles.ring, { borderColor: c.accent }, ring2]} />
        <Animated.View style={[{ width: 110, height: 110, borderRadius: 28, overflow: 'hidden', backgroundColor: c.surface, elevation: 8, shadowColor: c.primary, shadowOpacity: 0.25, shadowRadius: 18, shadowOffset: { width: 0, height: 8 } }, logoStyle]}>
          <Image source={require('../../assets/splash-icon.png')} style={{ width: 110, height: 110 }} resizeMode="contain" />
          <Animated.View style={[{ position: 'absolute', top: -20, bottom: -20, width: 34, backgroundColor: '#ffffff' }, shineStyle]} />
        </Animated.View>
      </View>
      <Animated.View style={[{ marginTop: 22, alignItems: 'center' }, wordsStyle]}>
        <Text variant="display">
          <Text variant="display" style={{ color: c.primary }}>
            Pato
          </Text>
          <Text variant="display" style={{ color: c.accent, fontWeight: '600' }}>
            Pro
          </Text>
        </Text>
      </Animated.View>
      <Animated.Text style={[{ marginTop: 6, fontSize: 12, fontWeight: '700', color: c.textFaint }, tagStyle]}>BY SHEZONI</Animated.Text>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  ring: { position: 'absolute', width: 120, height: 120, borderRadius: 60, borderWidth: 3 },
});
