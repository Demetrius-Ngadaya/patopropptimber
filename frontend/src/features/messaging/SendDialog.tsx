"use client";

import { useEffect, useState } from "react";
import { Send } from "lucide-react";
import { api } from "@/lib/api";
import { fieldErrors, useAction } from "@/lib/hooks";
import { Button, Field, Input, Modal } from "@/components/ui";
import { useT } from "@/lib/i18n";
import { ChannelPicker } from "./ChannelPicker";
import { useEnabledChannels, type Channel } from "./channels";

/**
 * "Send to the customer" dialog: pick SMS / WhatsApp / email, optionally
 * correct the phone or email, then send. Used for receipts and reminders.
 */
export function SendDialog({ open, onClose, title, description, endpoint, phone, email, editableContacts = false, defaults = ["sms"], note, onSent }: {
  open: boolean;
  onClose: () => void;
  title: string;
  description?: string;
  endpoint: string;
  phone?: string | null;
  email?: string | null;
  /** Let the user type a phone number / email for this one message (receipts). */
  editableContacts?: boolean;
  defaults?: Channel[];
  /** What each channel carries, when it differs from the usual wording. */
  note?: string;
  onSent?: () => void;
}) {
  const t = useT();
  const { channels: enabled } = useEnabledChannels();
  const [channels, setChannels] = useState<Channel[]>([]);
  const [to, setTo] = useState({ phone: "", email: "" });

  useEffect(() => {
    if (!open) return;
    setTo({ phone: phone ?? "", email: email ?? "" });
    setChannels(defaults.filter((c) => enabled.includes(c)));
    // eslint-disable-next-line react-hooks/exhaustive-deps -- reset only when the dialog opens
  }, [open]);

  const missing: Partial<Record<Channel, string>> = {
    ...(!to.phone ? { sms: t("No phone number"), whatsapp: t("No phone number") } : {}),
    ...(!to.email ? { email: t("No email address") } : {}),
  };
  const chosen = channels.filter((c) => !missing[c]);

  const send = useAction(
    () => api.post(endpoint, { channels: chosen, ...(editableContacts ? { phone: to.phone || null, email: to.email || null } : {}) }),
    { onSuccess: () => { onSent?.(); onClose(); } },
  );
  const errors = fieldErrors(send.error);

  return (
    <Modal open={open} onClose={onClose} title={title} description={description} size="md"
      footer={<><Button variant="ghost" onClick={onClose}>{t("Cancel")}</Button><Button icon={<Send className="size-4" />} loading={send.isPending} disabled={!chosen.length} onClick={() => send.mutate(undefined)}>{t("Send")}</Button></>}>
      <div className="space-y-4">
        {editableContacts && (
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t("Phone")} error={errors.phone}>{(p) => <Input {...p} inputMode="tel" value={to.phone} onChange={(e) => setTo({ ...to, phone: e.target.value })} placeholder="0712 345 678" />}</Field>
            <Field label={t("Email")} error={errors.email}>{(p) => <Input {...p} type="email" value={to.email} onChange={(e) => setTo({ ...to, email: e.target.value })} />}</Field>
          </div>
        )}
        <ChannelPicker value={chosen} onChange={setChannels} missing={missing} />
        <p className="text-xs text-ink-500">{note ?? t("SMS gets a short summary, WhatsApp a fuller message, and email the full details with a PDF attached.")}</p>
      </div>
    </Modal>
  );
}
