import { useState } from 'react';
import { SectionList, View } from 'react-native';
import { router } from 'expo-router';
import { useLive } from '@/lib/useLive';
import { money, time } from '@/lib/format';
import { listSales } from '@/db/repo';
import { useT } from '@/i18n';
import { Badge, EmptyState, ListRow, Screen, Segmented, SkeletonList, Text, space, useColors } from '@/ui';
import { SALE_SYNC, ScreenHeader } from '@/features/common';
import type { Sale } from '@/lib/types';

/** Recent sales on this phone, newest first, grouped by day. */
export default function Sales() {
  const t = useT();
  const c = useColors();
  const [filter, setFilter] = useState<'all' | 'unsynced'>('all');
  const sales = useLive(() => listSales(300, filter === 'unsynced'), ['sales'], [filter]);

  const sections = Object.values(
    (sales.data ?? []).reduce<Record<string, { title: string; total: number; data: Sale[] }>>((acc, s) => {
      const d = new Date(s.sold_at);
      const key = d.toDateString();
      acc[key] ??= { title: d.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'short' }), total: 0, data: [] };
      acc[key].data.push(s);
      if (s.status !== 'cancelled') acc[key].total += s.total;
      return acc;
    }, {}),
  );

  return (
    <Screen padded={false} edges={[]}>
      <ScreenHeader title={t('Sales')} subtitle={t('Last 14 days and everything sold on this phone')} />
      <View style={{ paddingHorizontal: space.lg, paddingBottom: space.sm }}>
        <Segmented value={filter} onChange={setFilter} options={[{ value: 'all', label: t('All') }, { value: 'unsynced', label: t('Not synced') }]} />
      </View>
      {sales.loading ? (
        <SkeletonList />
      ) : (
        <SectionList
          sections={sections}
          keyExtractor={(s) => s.key}
          stickySectionHeadersEnabled
          contentContainerStyle={{ paddingBottom: space.xxxl }}
          ListEmptyComponent={<EmptyState icon="receipt-outline" title={filter === 'unsynced' ? t('Everything is synced') : t('No sales yet')} tone={filter === 'unsynced' ? 'success' : 'primary'} />}
          renderSectionHeader={({ section }) => (
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', backgroundColor: c.bg, paddingHorizontal: space.lg, paddingVertical: space.sm }}>
              <Text variant="tiny" tone="muted" style={{ textTransform: 'uppercase' }}>
                {section.title}
              </Text>
              <Text variant="tiny" tone="muted" numeric>
                {money(section.total)}
              </Text>
            </View>
          )}
          renderItem={({ item: s }) => {
            const state = SALE_SYNC[s.sync_state];
            return (
              <ListRow
                icon={s.status === 'cancelled' ? 'close-circle-outline' : state.icon}
                iconTone={s.status === 'cancelled' ? 'neutral' : state.tone}
                title={`${s.number ?? s.local_number} · ${s.customer_name ?? t('Walk-in')}`}
                subtitle={`${time(s.sold_at)} · ${(s.items_count === 1 ? t('1 item') : t('{n} items', { n: s.items_count }))}${s.sync_state !== 'synced' ? ` · ${t(state.label)}` : ''}`}
                right={
                  <View style={{ alignItems: 'flex-end', gap: 2 }}>
                    <Text variant="bodyStrong" numeric style={s.status === 'cancelled' ? { textDecorationLine: 'line-through' } : undefined}>
                      {money(s.total, false)}
                    </Text>
                    {s.balance > 0 ? <Badge label={t('Credit')} tone="warning" /> : null}
                  </View>
                }
                onPress={() => router.push({ pathname: '/sales/[key]', params: { key: s.key } })}
              />
            );
          }}
        />
      )}
    </Screen>
  );
}
