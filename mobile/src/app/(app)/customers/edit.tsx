import { useEffect, useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { useCan } from '@/lib/session';
import { getCustomer } from '@/db/repo';
import { createCustomer, updateCustomer, type CustomerInput } from '@/db/commands';
import { syncSoon } from '@/sync/manager';
import { useCart } from '@/features/pos/cart';
import { useT } from '@/i18n';
import { Button, Card, Input, NumberInput, Screen, Segmented, SwitchRow, Text, space, toast } from '@/ui';
import { ScreenHeader } from '@/features/common';
import type { Customer } from '@/lib/types';

/** New customer or edit (offline too). With ?pick=1 the new customer goes straight into the cart. */
export default function EditCustomer() {
  const t = useT();
  const { key, pick, name: prefill } = useLocalSearchParams<{ key?: string; pick?: string; name?: string }>();
  const canCredit = useCan('customers.update|customers.create');
  const [existing, setExisting] = useState<Customer | null>(null);
  const [form, setForm] = useState<CustomerInput>({ name: prefill ?? '', phone: '', email: '', address: '', city: '', customer_type: 'retail', allow_credit: false, credit_limit: 0 });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!key) return;
    getCustomer(String(key)).then((cu) => {
      if (!cu) return;
      setExisting(cu);
      setForm({ name: cu.name, phone: cu.phone ?? '', email: cu.email ?? '', address: cu.address ?? '', city: cu.city ?? '', customer_type: cu.customer_type ?? 'retail', allow_credit: cu.allow_credit, credit_limit: cu.credit_limit });
    });
  }, [key]);

  const set = <K extends keyof CustomerInput>(k: K, v: CustomerInput[K]) => setForm((f) => ({ ...f, [k]: v }));

  const save = async () => {
    // Same rules as the server, so the change is not refused later.
    const e: Record<string, string> = {};
    if (form.name.trim().length < 2) e.name = t('Enter the customer\'s name.');
    if (form.phone && !/^[0-9+\s\-()]{9,20}$/.test(form.phone)) e.phone = t('Enter a valid phone number, e.g. 0712 345 678.');
    if (form.email && !/^\S+@\S+\.\S+$/.test(form.email)) e.email = t('Enter a valid email address.');
    setErrors(e);
    if (Object.keys(e).length) return;
    setSaving(true);
    const clean: CustomerInput = { ...form, name: form.name.trim(), phone: form.phone?.trim() || null, email: form.email?.trim() || null, address: form.address?.trim() || null, city: form.city?.trim() || null };
    try {
      if (existing) {
        await updateCustomer(existing, clean);
        toast.success(t('Saved.'));
        router.back();
      } else {
        const newKey = await createCustomer(clean);
        toast.success(t('{name} added.', { name: clean.name }));
        if (pick) {
          const cu = await getCustomer(newKey);
          if (cu) useCart.getState().setCustomer(cu);
        }
        router.back();
      }
      syncSoon();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : String(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Screen padded={false} edges={[]}>
      <ScreenHeader title={existing ? t('Edit customer') : t('New customer')} />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: space.lg, gap: space.lg, paddingBottom: space.xxxl }}>
          <Card style={{ gap: space.md }}>
            <Input label={t('Name')} value={form.name} onChangeText={(v) => set('name', v)} error={errors.name} autoCapitalize="words" icon="person-outline" autoFocus={!existing} />
            <Input label={t('Phone')} value={form.phone ?? ''} onChangeText={(v) => set('phone', v)} error={errors.phone} keyboardType="phone-pad" icon="call-outline" placeholder="0712 345 678" />
            <Input label={t('Email (optional)')} value={form.email ?? ''} onChangeText={(v) => set('email', v)} error={errors.email} keyboardType="email-address" autoCapitalize="none" icon="mail-outline" />
            <Input label={t('Address')} value={form.address ?? ''} onChangeText={(v) => set('address', v)} icon="location-outline" placeholder={t('Street, area')} />
            <Input label={t('Town')} value={form.city ?? ''} onChangeText={(v) => set('city', v)} icon="business-outline" />
          </Card>
          <Card style={{ gap: space.md }}>
            <Text variant="label" tone="muted">
              {t('Customer type')}
            </Text>
            <Segmented value={form.customer_type} onChange={(v) => set('customer_type', v)} options={[{ value: 'retail', label: t('Retail') }, { value: 'wholesale', label: t('Wholesale') }, { value: 'institution', label: t('Institution') }]} />
            {canCredit ? (
              <>
                <SwitchRow title={t('May buy on credit')} value={form.allow_credit} onChange={(v) => set('allow_credit', v)} icon="card-outline" />
                {form.allow_credit ? <NumberInput label={t('Credit limit (0 = no limit)')} value={form.credit_limit} onChange={(n) => set('credit_limit', n ?? 0)} /> : null}
              </>
            ) : null}
          </Card>
          <View>
            <Button title={existing ? t('Save changes') : t('Add customer')} icon="checkmark" size="lg" loading={saving} onPress={save} />
            <Text variant="caption" tone="faint" center style={{ marginTop: space.sm }}>
              {t('Saved on this phone at once, sent to the server with the next sync.')}
            </Text>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </Screen>
  );
}
