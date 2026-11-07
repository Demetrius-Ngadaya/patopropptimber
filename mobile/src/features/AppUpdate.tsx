import { useEffect } from 'react';
import { AppState, Linking, Platform, Pressable, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import * as Application from 'expo-application';
import { create } from 'zustand';
import { api } from '@/lib/api';
import { getMeta, setMeta } from '@/db/meta';
import { isNewer, shouldShowCard } from '@/lib/update';
import { t as translate, useT } from '@/i18n';
import { Button, Text, radius, space, toast, useColors } from '@/ui';

/**
 * The app is not in an app store: new versions are published from the
 * PatoPro website (Back office → Mobile app downloads). This checks which
 * version is offered there and tells the user when theirs is older.
 */
export type OfferedRelease = {
  version: string;
  build_number: number;
  file_size: number | null;
  notes: string | null;
  is_required: boolean;
  download_url: string;
};

const CHECK_EVERY_MS = 6 * 60 * 60 * 1000;
const DISMISSED_KEY = 'app_update.dismissed_build';

/** The build number (Android versionCode) of the app that is running. */
export function installedBuild(): number {
  return Number(Application.nativeBuildVersion ?? 0) || 0;
}


type UpdateState = {
  offered: OfferedRelease | null;
  dismissedBuild: number;
  checkedAt: number;
  checking: boolean;
  check: (opts?: { force?: boolean }) => Promise<OfferedRelease | null>;
  dismiss: () => void;
};

export const useAppUpdate = create<UpdateState>((set, get) => ({
  offered: null,
  dismissedBuild: 0,
  checkedAt: 0,
  checking: false,
  async check({ force } = {}) {
    if (Platform.OS !== 'android') return null;
    if (get().checking || (!force && Date.now() - get().checkedAt < CHECK_EVERY_MS)) return get().offered;
    set({ checking: true });
    try {
      const [res, dismissedBuild] = await Promise.all([
        api.get<OfferedRelease | null>('public/app', undefined, { auth: false, timeoutMs: 10000 }),
        getMeta<number>(DISMISSED_KEY, 0),
      ]);
      const offered = isNewer(res.data, installedBuild()) ? res.data : null;
      set({ offered, dismissedBuild, checkedAt: Date.now() });
      return offered;
    } catch {
      // Offline or server unreachable: try again on the next check.
      return get().offered;
    } finally {
      set({ checking: false });
    }
  },
  dismiss() {
    const build = get().offered?.build_number ?? 0;
    set({ dismissedBuild: build });
    setMeta(DISMISSED_KEY, build).catch(() => {});
  },
}));

export async function openDownload(url: string) {
  try {
    await Linking.openURL(url);
  } catch {
    toast.error(translate('Could not open the download link.'));
  }
}

/** Checks on start and whenever the app comes back to the front. */
export function useUpdateCheck() {
  useEffect(() => {
    useAppUpdate.getState().check();
    const sub = AppState.addEventListener('change', (s) => {
      if (s === 'active') useAppUpdate.getState().check();
    });
    return () => sub.remove();
  }, []);
}

function sizeLabel(bytes: number | null): string {
  return bytes ? `${(bytes / 1048576).toFixed(1)} MB` : '';
}

/** A slim card under the header for an optional update; a full screen for a required one. */
export function UpdateNotice() {
  const t = useT();
  const c = useColors();
  const offered = useAppUpdate((s) => s.offered);
  const dismissed = useAppUpdate((s) => s.dismissedBuild);
  useUpdateCheck();

  if (!offered) return null;
  const download = () => openDownload(offered.download_url);

  if (offered.is_required) {
    return (
      <SafeAreaView style={{ position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, backgroundColor: c.bg, zIndex: 998, alignItems: 'center', justifyContent: 'center', gap: space.lg, padding: space.xl }}>
        <View style={{ width: 64, height: 64, borderRadius: 20, backgroundColor: c.primary, alignItems: 'center', justifyContent: 'center' }}>
          <Ionicons name="arrow-down-circle" size={34} color="#fff" />
        </View>
        <Text variant="title" center>{t('Update PatoPro to continue')}</Text>
        <Text tone="muted" center>
          {t('Version {version} is required. Your unsynced sales are safe on this phone and will sync after the update.', { version: offered.version })}
        </Text>
        {offered.notes ? (
          <View style={{ alignSelf: 'stretch', backgroundColor: c.surface, borderRadius: radius.md, padding: space.lg }}>
            <Text variant="label">{t("What's new")}</Text>
            <Text tone="muted" style={{ marginTop: 4 }}>{offered.notes}</Text>
          </View>
        ) : null}
        <Button title={t('Download update')} icon="download-outline" full onPress={download} />
        <Text tone="faint" center variant="caption">
          {[sizeLabel(offered.file_size), t('Open the downloaded file and tap “Update”.')].filter(Boolean).join(' · ')}
        </Text>
      </SafeAreaView>
    );
  }

  if (!shouldShowCard(offered, dismissed)) return null;
  return (
    <View style={{ position: 'absolute', left: space.md, right: space.md, bottom: 96, zIndex: 50 }}>
      <Pressable
        onPress={download}
        accessibilityRole="button"
        accessibilityLabel={t('Download update')}
        style={({ pressed }) => ({
          flexDirection: 'row',
          alignItems: 'center',
          gap: space.md,
          padding: space.md,
          borderRadius: radius.lg,
          backgroundColor: pressed ? c.primaryPressed : c.primary,
          shadowColor: '#000',
          shadowOpacity: 0.18,
          shadowRadius: 10,
          shadowOffset: { width: 0, height: 4 },
          elevation: 6,
        })}
      >
        <Ionicons name="arrow-down-circle" size={28} color="#fff" />
        <View style={{ flex: 1 }}>
          <Text style={{ color: '#fff', fontWeight: '700' }}>{t('New version {version} available', { version: offered.version })}</Text>
          <Text style={{ color: '#ffffffcc' }} variant="caption" numberOfLines={2}>{offered.notes || t('Tap to download and install.')}</Text>
        </View>
        <Pressable onPress={() => useAppUpdate.getState().dismiss()} hitSlop={12} accessibilityLabel={t('Later')}>
          <Ionicons name="close" size={22} color="#fff" />
        </Pressable>
      </Pressable>
    </View>
  );
}
