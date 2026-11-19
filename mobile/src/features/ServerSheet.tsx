import { useEffect, useState } from 'react';
import { View } from 'react-native';
import { useSession } from '@/lib/session';
import { useT } from '@/i18n';
import { Badge, Button, Input, Sheet, Text, space } from '@/ui';
import { checkServer } from '@/lib/server';

/**
 * Changing the server address: for the Software Engineer only (sign-in screen
 * code 111999, or Settings when signed in as the engineer). The address is tested
 * before it is saved, so a typo cannot cut the phone off.
 */
export function ServerSheet({ visible, onClose }: { visible: boolean; onClose: () => void }) {
  const t = useT();
  const { apiUrl, setServer } = useSession();
  const [draft, setDraft] = useState(apiUrl);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    if (!visible) return;
    // eslint-disable-next-line react-hooks/set-state-in-effect -- start from the address in use each time it opens
    setDraft(apiUrl.includes('example.com') ? '' : apiUrl);
    setError(null);
    setSaved(false);
  }, [visible, apiUrl]);

  const save = async (reset = false) => {
    setError(null);
    setBusy(true);
    try {
      if (!reset) {
        const ok = await checkServer(draft);
        if (!ok) {
          setError(t('No answer from this address. Check it and try again.'));
          return;
        }
      }
      await setServer(reset ? '' : draft, 'manual');
      setSaved(true);
      setTimeout(onClose, 700);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Sheet visible={visible} onClose={onClose} title={t('Server address')} footer={<Button title={t('Test and save')} icon="checkmark" loading={busy} onPress={() => save(false)} />}>
      <View style={{ gap: space.md }}>
        <Badge label={t('Software Engineer only')} tone="warning" icon="lock-closed" />
        <Input value={draft} onChangeText={setDraft} placeholder="https://api.yourshop.co.tz" autoCapitalize="none" autoCorrect={false} keyboardType="url" icon="globe-outline" />
        {error ? (
          <Text variant="label" tone="danger">
            {error}
          </Text>
        ) : null}
        {saved ? (
          <Text variant="label" tone="primary">
            {t('Saved. The app now uses this server.')}
          </Text>
        ) : null}
        <Button title={t('Use the built-in address')} variant="ghost" size="sm" onPress={() => save(true)} />
      </View>
    </Sheet>
  );
}
