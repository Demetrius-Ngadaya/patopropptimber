"use client";

import { useState } from "react";
import { Sparkles } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAction } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import type { Product } from "@/lib/types";
import { Button, Field, Input, Panel, Textarea } from "@/components/ui";

type Draft = { description: string; description_sw: string };

/** AI drafts the online-shop description in English and Kiswahili; staff review and edit before saving. */
export function AiDescriptionPanel({ product }: { product: Product }) {
  const t = useT();
  const [notes, setNotes] = useState("");
  const [draft, setDraft] = useState<Draft | null>(null);

  const write = useAction(() => api.post<ApiResponse<Draft>>(`admin/ai/products/${product.id}/describe`, { notes: notes || undefined }), {
    silent: true,
    onSuccess: (res) => setDraft(res.data),
  });
  const save = useAction(() => api.put(`admin/products/${product.id}/descriptions`, draft), {
    invalidate: [[`admin/products/${product.id}`]],
    onSuccess: () => setDraft(null),
  });

  return (
    <Panel title={<span className="flex items-center gap-1.5"><Sparkles className="size-4 text-saffron-500" /> {t("AI shop description")}</span>}>
      <p className="text-sm text-ink-500">{t("Write a short English and Kiswahili description for the online shop from the product details.")}</p>
      <Field label={t("Anything to mention? (optional)")} className="mt-3">
        {(p) => <Input {...p} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder={t("e.g. made in Tanzania, keeps 6 months")} maxLength={500} />}
      </Field>
      <Button className="mt-3" variant="secondary" icon={<Sparkles className="size-4" />} loading={write.isPending} onClick={() => write.mutate(undefined)}>
        {draft ? t("Write again") : t("Write with AI")}
      </Button>
      {draft && (
        <div className="mt-4 space-y-3">
          <Field label={t("English")}>{(p) => <Textarea {...p} rows={3} value={draft.description} onChange={(e) => setDraft({ ...draft, description: e.target.value })} />}</Field>
          <Field label={t("Kiswahili")}>{(p) => <Textarea {...p} rows={3} value={draft.description_sw} onChange={(e) => setDraft({ ...draft, description_sw: e.target.value })} />}</Field>
          <div className="flex gap-2">
            <Button loading={save.isPending} onClick={() => save.mutate(undefined)}>{t("Save to product")}</Button>
            <Button variant="ghost" onClick={() => setDraft(null)}>{t("Discard")}</Button>
          </div>
        </div>
      )}
    </Panel>
  );
}
