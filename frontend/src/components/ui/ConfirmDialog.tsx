"use client";

import { useEffect, useState } from "react";
import { Button } from "./Button";
import { Field, Textarea } from "./Form";
import { Modal } from "./Modal";
import { useT } from "@/lib/i18n";

type ConfirmProps = {
  open: boolean;
  onClose: () => void;
  onConfirm: (reason: string) => void;
  title: string;
  message: React.ReactNode;
  confirmLabel: string;
  tone?: "danger" | "primary";
  /** Ask for a reason (kept in the audit log). */
  requireReason?: boolean;
  loading?: boolean;
};

export function ConfirmDialog({ open, onClose, onConfirm, title, message, confirmLabel, tone = "danger", requireReason, loading }: ConfirmProps) {
  const t = useT();
  const [reason, setReason] = useState("");
  useEffect(() => {
    if (open) setReason("");
  }, [open]);

  const blocked = requireReason && reason.trim().length < 3;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>{t("Keep it")}</Button>
          <Button variant={tone} loading={loading} disabled={blocked} onClick={() => onConfirm(reason.trim())} data-autofocus>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="space-y-4 text-sm text-ink-700">
        <div>{message}</div>
        {requireReason && (
          <Field label={t("Reason")} required hint={t("This is saved in the audit log.")}>
            {(p) => <Textarea {...p} value={reason} onChange={(e) => setReason(e.target.value)} rows={3} maxLength={500} />}
          </Field>
        )}
      </div>
    </Modal>
  );
}
