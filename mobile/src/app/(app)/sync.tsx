import { useState } from 'react';
import { ScrollView, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { usePrefs } from '@/lib/prefs';
import { useLive } from '@/lib/useLive';
import { ago, time } from '@/lib/format';
import { getDb } from '@/db/database';
import { discardEntry, listOutbox, retryEntry, type OutboxEntry } from '@/db/outbox';
import { requestSync, useSync } from '@/sync/manager';
import { useT } from '@/i18n';
import { Badge, Button, Card, Group, IconTile, ListRow, Screen, Section, Segmented, SwitchRow, Text, confirm, space, toast, useColors } from '@/ui';
import { ScreenHeader } from '@/features/common';

const TYPE: Record<string, string> = {
  'sale.create': 'Sale',
  'customer.create': 'New customer',
  'customer.update': 'Customer change',
  'customer.payment': 'Debt payment',
  'order.status': 'Order status',
  'order.complete': 'Order completed',
  'notification.read': 'Notification read',
  'notification.read_all': 'All notifications read',
};

/** Everything about sync: state, Sync now, what waits, what needs fixing, history. */
export default function SyncCentre() {
  const t = useT();
  const c = useColors();
  const sync = useSync();
  const prefs = usePrefs();
  const [tab, setTab] = useState<'waiting' | 'sent' | 'history'>('waiting');

  const attention = useLive(() => listOutbox(['attention']), ['outbox'], []);
  const waiting = useLive(() => listOutbox(['pending', 'failed']), ['outbox'], []);
  const sent = useLive(() => listOutbox(['done'], 50), ['outbox'], []);
  const history = useLive(
    async () => (await getDb()).getAllAsync<{ id: number; at: string; trigger: string; ok: number; pushed: number; pulled: number; duration_ms: number; message: string | null }>('SELECT * FROM sync_log ORDER BY id DESC LIMIT 30'),
    ['outbox'],
    [sync.lastSyncAt],
  );

  const syncNow = async () => {
    const report = await requestSync('manual', { force: true });
    if (!report) toast.warning(t('No internet connection. Your work is safe on this phone.'));
    else if (report.ok) toast.success(t('Synced: {up} sent, {down} received.', { up: report.applied, down: report.pulled }));
    else if (report.offline) toast.warning(t('No internet connection. Your work is safe on this phone.'));
    else toast.error(report.error ?? t('Sync failed.'));
  };

  const state = sync.running
    ? { icon: 'sync' as const, tone: 'primary' as const, title: t('Syncing…'), text: sync.progress?.startsWith('push') ? t('Sending changes') : t('Getting the latest from the server') }
    : sync.online === false
      ? { icon: 'cloud-offline' as const, tone: 'warning' as const, title: t('Offline'), text: t('Keep working: everything syncs when the internet is back.') }
      : sync.attention
        ? { icon: 'alert-circle' as const, tone: 'danger' as const, title: t('{n} changes need you', { n: sync.attention }), text: t('The server did not accept them. Fix or discard them below.') }
        : sync.waiting
          ? { icon: 'cloud-upload' as const, tone: 'warning' as const, title: t('{n} changes waiting', { n: sync.waiting }), text: t('They are sent automatically.') }
          : { icon: 'cloud-done' as const, tone: 'success' as const, title: t('All synced'), text: t('This phone and the server match.') };

  return (
    <Screen padded={false} edges={[]}>
      <ScreenHeader title={t('Sync centre')} />
      <ScrollView contentContainerStyle={{ padding: space.lg, paddingBottom: space.xxxl }}>
        <Card style={{ alignItems: 'center', gap: space.sm, paddingVertical: space.xl }}>
          <IconTile name={state.icon} tone={state.tone} size={64} />
          <Text variant="title" center>
            {state.title}
          </Text>
          <Text tone="muted" center>
            {state.text}
          </Text>
          <Text variant="caption" tone="faint" center>
            {t('Last successful sync: {when}', { when: ago(sync.lastOkAt, t) })}
            {sync.connection === 'wifi' ? ' · Wi-Fi' : sync.connection === 'cellular' ? ` · ${t('Mobile data')}` : sync.connection === 'ethernet' ? ' · Ethernet' : ''}
          </Text>
          <Button title={sync.running ? t('Syncing…') : t('Sync now')} icon="sync" size="lg" loading={sync.running} onPress={syncNow} style={{ alignSelf: 'stretch', marginTop: space.md }} />
        </Card>

        <Section title={t('Automatic sync')}>
          <Card padded={false} style={{ paddingHorizontal: space.lg }}>
            <SwitchRow icon="flash-outline" title={t('Sync automatically')} subtitle={t('When the internet returns, when the app opens, after every change and every few minutes.')} value={prefs.sync.auto} onChange={(v) => prefs.update({ sync: { auto: v } })} />
            {prefs.sync.auto ? (
              <View style={{ paddingBottom: space.md, gap: space.sm }}>
                <Text variant="label" tone="muted">
                  {t('While the app is open, also every')}
                </Text>
                <Segmented
                  value={String(prefs.sync.intervalMinutes)}
                  onChange={(v) => prefs.update({ sync: { intervalMinutes: Number(v) } })}
                  options={['1', '2', '5', '15'].map((m) => ({ value: m, label: t('{n} min', { n: m }) }))}
                />
              </View>
            ) : null}
            <SwitchRow icon="moon-outline" title={t('Sync in the background')} subtitle={t('About every 15 minutes, even when the app is closed (the phone decides the exact time).')} value={prefs.sync.background} onChange={(v) => prefs.update({ sync: { background: v } })} />
            <SwitchRow icon="wifi-outline" title={t('Only on Wi-Fi')} subtitle={t('Save mobile data. "Sync now" always works.')} value={prefs.sync.wifiOnly} onChange={(v) => prefs.update({ sync: { wifiOnly: v } })} />
          </Card>
        </Section>

        {attention.data?.length ? (
          <Section title={t('Needs your attention')}>
            {attention.data.map((e) => (
              <AttentionCard key={e.id} entry={e} />
            ))}
          </Section>
        ) : null}

        {sync.otherUsers ? (
          <Card style={{ marginTop: space.lg, backgroundColor: c.warningSoft, borderColor: c.warningSoft }}>
            <Text variant="label" tone="warning">
              {t('{n} changes from another user wait on this phone. They are sent when that user signs in.', { n: sync.otherUsers })}
            </Text>
          </Card>
        ) : null}

        <View style={{ marginTop: space.xl }}>
          <Segmented
            value={tab}
            onChange={setTab}
            options={[
              { value: 'waiting', label: `${t('Waiting')} (${waiting.data?.length ?? 0})` },
              { value: 'sent', label: t('Sent') },
              { value: 'history', label: t('History') },
            ]}
          />
        </View>

        <View style={{ marginTop: space.md }}>
          {tab === 'waiting' ? (
            waiting.data?.length ? (
              <Group>
                {waiting.data.map((e) => (
                  <ListRow
                    key={e.id}
                    icon={e.status === 'failed' ? 'refresh' : 'cloud-upload-outline'}
                    iconTone="warning"
                    title={`${t(TYPE[e.type] ?? e.type)}${e.summary ? ` · ${e.summary}` : ''}`}
                    subtitle={e.status === 'failed' ? `${t('Retry {when}', { when: new Date(e.next_attempt_at) > new Date() ? t('at {time}', { time: time(new Date(e.next_attempt_at).toISOString()) }) : t('now') })} · ${e.last_error ?? ''}` : `${t('Made {when}', { when: ago(e.created_at, t) })}`}
                    chevron={false}
                  />
                ))}
              </Group>
            ) : (
              <Text tone="muted" center style={{ padding: space.lg }}>
                {t('Nothing waiting.')}
              </Text>
            )
          ) : null}

          {tab === 'sent' ? (
            <Group>
              {(sent.data ?? []).map((e) => (
                <ListRow key={e.id} icon="checkmark-circle" iconTone="success" title={`${t(TYPE[e.type] ?? e.type)}${e.summary ? ` · ${e.summary}` : ''}`} subtitle={t('Sent {when}', { when: ago(e.done_at, t) })} chevron={false} />
              ))}
            </Group>
          ) : null}

          {tab === 'history' ? (
            <Group>
              {(history.data ?? []).map((h) => (
                <ListRow
                  key={h.id}
                  icon={h.ok ? 'checkmark-circle-outline' : 'close-circle-outline'}
                  iconTone={h.ok ? 'success' : 'danger'}
                  title={`${time(h.at)} · ${t(TRIGGER[h.trigger] ?? h.trigger)}`}
                  subtitle={h.ok ? t('{up} sent · {down} received · {ms} ms', { up: h.pushed, down: h.pulled, ms: h.duration_ms }) : h.message}
                  chevron={false}
                />
              ))}
            </Group>
          ) : null}
        </View>

        <View style={{ flexDirection: 'row', gap: 6, alignItems: 'center', justifyContent: 'center', marginTop: space.xl }}>
          <Ionicons name="shield-checkmark-outline" size={14} color={c.textFaint} />
          <Text variant="caption" tone="faint" center>
            {t('Each change is sent once, even if the connection drops in the middle.')}
          </Text>
        </View>
      </ScrollView>
    </Screen>
  );
}

const TRIGGER: Record<string, string> = {
  manual: 'Sync now',
  startup: 'App opened',
  online: 'Internet back',
  foreground: 'App opened',
  interval: 'Automatic',
  change: 'After a change',
  push: 'Notification',
  background: 'Background',
  branch: 'Branch changed',
  login: 'Signed in',
};

function AttentionCard({ entry }: { entry: OutboxEntry }) {
  const t = useT();
  const c = useColors();
  const errors = entry.errors ? Object.values(entry.errors).flat() : [];
  return (
    <Card style={{ gap: space.sm, borderColor: c.danger }}>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: space.sm }}>
        <Badge label={t(TYPE[entry.type] ?? entry.type)} tone="danger" icon="alert-circle" />
        <Text variant="caption" tone="faint">
          {ago(entry.created_at, t)}
        </Text>
      </View>
      {entry.summary ? <Text variant="bodyStrong">{entry.summary}</Text> : null}
      <Text tone="danger">{entry.last_error}</Text>
      {errors.filter((e) => e !== entry.last_error).map((e, i) => (
        <Text key={i} variant="caption" tone="muted">
          • {e}
        </Text>
      ))}
      <View style={{ flexDirection: 'row', gap: space.sm, marginTop: space.sm }}>
        <Button
          title={t('Try again')}
          icon="refresh"
          variant="secondary"
          size="sm"
          style={{ flex: 1 }}
          onPress={async () => {
            await retryEntry(entry.id);
            requestSync('manual', { force: true });
          }}
        />
        <Button
          title={t('Discard')}
          icon="trash-outline"
          variant="danger"
          size="sm"
          style={{ flex: 1 }}
          onPress={async () => {
            const ok = await confirm({
              title: t('Discard this change?'),
              message: entry.type === 'sale.create' ? t('The sale is removed from this phone. If the goods left the shop, record the sale again correctly.') : t('It is removed from this phone and never sent.'),
              confirm: t('Discard'),
              danger: true,
            });
            if (ok) {
              const n = await discardEntry(entry.id);
              toast.info(n > 1 ? t('Discarded, with {n} changes that depended on it.', { n: n - 1 }) : t('Discarded.'));
            }
          }}
        />
      </View>
    </Card>
  );
}
