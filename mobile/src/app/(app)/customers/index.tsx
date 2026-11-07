import { useState } from 'react';
import { FlatList, View } from 'react-native';
import { router } from 'expo-router';
import { useCan } from '@/lib/session';
import { useDebounced, useLive } from '@/lib/useLive';
import { money } from '@/lib/format';
import { listCustomers } from '@/db/repo';
import { useT } from '@/i18n';
import { Badge, Button, EmptyState, ListRow, Screen, SearchBar, Segmented, SkeletonList, Text, space } from '@/ui';
import { ScreenHeader } from '@/features/common';

export default function Customers() {
  const t = useT();
  const canCreate = useCan('customers.create');
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState<'all' | 'owing'>('all');
  const [page, setPage] = useState(1);
  const term = useDebounced(search, 200);
  const list = useLive(() => listCustomers(term, page * 60, 0, filter === 'owing'), ['customers'], [term, filter, page]);

  return (
    <Screen padded={false} edges={[]}>
      <ScreenHeader title={t('Customers')} right={canCreate ? <Button title={t('Add')} icon="person-add-outline" size="sm" variant="ghost" onPress={() => router.push('/customers/edit')} /> : undefined} />
      <View style={{ paddingHorizontal: space.lg, gap: space.md, paddingBottom: space.sm }}>
        <SearchBar value={search} onChangeText={(s) => { setSearch(s); setPage(1); }} placeholder={t('Name, phone or code')} />
        <Segmented value={filter} onChange={setFilter} options={[{ value: 'all', label: t('All') }, { value: 'owing', label: t('Owe money') }]} />
      </View>
      {list.loading ? (
        <SkeletonList />
      ) : (
        <FlatList
          data={list.data}
          keyExtractor={(c) => c.key}
          keyboardDismissMode="on-drag"
          onEndReached={() => list.data && list.data.length >= page * 60 && setPage((p) => p + 1)}
          ListEmptyComponent={<EmptyState icon="people-outline" title={filter === 'owing' ? t('Nobody owes money') : t('No customers found')} tone={filter === 'owing' ? 'success' : 'primary'} />}
          renderItem={({ item: cu }) => (
            <ListRow
              icon="person-outline"
              iconTone={cu.balance > 0 ? 'warning' : 'primary'}
              title={cu.name}
              subtitle={[cu.phone, cu.code].filter(Boolean).join(' · ') || null}
              meta={cu.pending ? <Badge label={t('Not synced yet')} tone="warning" icon="cloud-upload" /> : null}
              right={cu.balance > 0 ? <Text variant="bodyStrong" tone="warning" numeric>{money(cu.balance, false)}</Text> : undefined}
              onPress={() => router.push({ pathname: '/customers/[key]', params: { key: cu.key } })}
            />
          )}
        />
      )}
    </Screen>
  );
}
