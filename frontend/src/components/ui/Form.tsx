"use client";

import { forwardRef, useId, useState } from "react";
import { cn } from "@/lib/format";

const control =
  "w-full rounded-[var(--radius-control)] border border-ink-200 bg-surface px-3 text-sm text-ink-900 placeholder:text-ink-400 transition-colors focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 disabled:bg-ink-50 disabled:text-ink-500 aria-[invalid=true]:border-ember-500 aria-[invalid=true]:focus:ring-ember-500/20";

export const Input = forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>(function Input({ className, ...rest }, ref) {
  if (rest.type === "number") return <NumericInput ref={ref} className={cn(control, "h-10", className)} {...rest} />;
  return <input ref={ref} className={cn(control, "h-10", className)} {...rest} />;
});

/** Keeps only what a number may contain: digits, one decimal point (when allowed), a leading minus (when allowed). */
export function cleanNumber(raw: string, decimals: boolean, negative: boolean): string {
  let s = raw.replace(/[\s,_]/g, "").replace(decimals ? /[^0-9.-]/g : /[^0-9-]/g, "");
  const minus = negative && s.startsWith("-");
  s = s.replace(/-/g, "");
  if (decimals) {
    const dot = s.indexOf(".");
    if (dot !== -1) s = s.slice(0, dot + 1) + s.slice(dot + 1).replace(/\./g, "");
  }
  return (minus ? "-" : "") + s;
}

const asNumber = (v: unknown) => (v === "" || v === null || v === undefined ? NaN : Number(String(v).replace(/,/g, "")));

/**
 * A number field people can type into comfortably: no up/down arrows, no
 * scroll-wheel changes, the number keypad on phones, and only digits accepted
 * (a decimal point when step="any" or a decimal step; a minus when min < 0).
 * Drop-in for <input type="number">: same value / onChange / min / max / step.
 * While typing, "1." or an empty box stay as typed until the number is complete.
 */
export const NumericInput = forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>(function NumericInput(
  { step, min, max, value, onChange, onFocus, onBlur, ...rest },
  ref,
) {
  const decimals = step === "any" || (step !== undefined && String(step).includes("."));
  const negative = min !== undefined && Number(min) < 0;
  const [draft, setDraft] = useState<string | null>(null);
  const controlled = value !== undefined;
  // Show what the person is typing while it means the same number as the value; otherwise the value.
  const shown = controlled ? (draft !== null && (asNumber(draft) === asNumber(value) || (draft.replace(/[-.]/g, "") === "" && !asNumber(value))) ? draft : value ?? "") : undefined;

  return (
    <input
      ref={ref}
      inputMode={decimals ? "decimal" : "numeric"}
      autoComplete="off"
      data-min={min}
      data-max={max}
      {...rest}
      type="text"
      {...(controlled ? { value: shown as string | number } : {})}
      onFocus={(e) => { setDraft(e.target.value); onFocus?.(e); }}
      onBlur={(e) => { setDraft(null); onBlur?.(e); }}
      onChange={(e) => {
        const cleaned = cleanNumber(e.target.value, decimals, negative);
        if (cleaned !== e.target.value) e.target.value = cleaned;
        setDraft(cleaned);
        onChange?.(e);
      }}
    />
  );
});

export const Textarea = forwardRef<HTMLTextAreaElement, React.TextareaHTMLAttributes<HTMLTextAreaElement>>(function Textarea({ className, ...rest }, ref) {
  return <textarea ref={ref} className={cn(control, "min-h-20 py-2", className)} {...rest} />;
});

export const Select = forwardRef<HTMLSelectElement, React.SelectHTMLAttributes<HTMLSelectElement>>(function Select({ className, children, ...rest }, ref) {
  return (
    <select ref={ref} className={cn(control, "h-10 pr-8", className)} {...rest}>
      {children}
    </select>
  );
});

type FieldProps = {
  label: string;
  error?: string;
  hint?: string;
  required?: boolean;
  className?: string;
  children: (props: { id: string; "aria-invalid": boolean; "aria-describedby"?: string }) => React.ReactNode;
};

/** Label + control + hint / error, wired up for screen readers. */
export function Field({ label, error, hint, required, className, children }: FieldProps) {
  const id = useId();
  const describedBy = error ? `${id}-error` : hint ? `${id}-hint` : undefined;
  return (
    <div className={cn("flex flex-col gap-1.5", className)}>
      <label htmlFor={id} className="text-[13px] font-medium text-ink-700">
        {label}
        {required && <span className="ml-0.5 text-ember-600" aria-hidden>*</span>}
      </label>
      {children({ id, "aria-invalid": Boolean(error), "aria-describedby": describedBy })}
      {error ? (
        <p id={`${id}-error`} className="text-[12.5px] text-ember-600">{error}</p>
      ) : hint ? (
        <p id={`${id}-hint`} className="text-[12.5px] text-ink-500">{hint}</p>
      ) : null}
    </div>
  );
}

export function Switch({ checked, onChange, label, description, disabled }: { checked: boolean; onChange: (v: boolean) => void; label: string; description?: string; disabled?: boolean }) {
  const id = useId();
  return (
    <div className="flex items-start justify-between gap-4">
      <div>
        <label htmlFor={id} className="text-sm font-medium text-ink-800">{label}</label>
        {description && <p className="text-[12.5px] text-ink-500">{description}</p>}
      </div>
      <button
        id={id}
        type="button"
        role="switch"
        aria-checked={checked}
        disabled={disabled}
        onClick={() => onChange(!checked)}
        className={cn(
          "relative mt-0.5 inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:opacity-50",
          checked ? "bg-brand-600" : "bg-ink-300",
        )}
      >
        <span className={cn("inline-block size-5 rounded-full bg-surface shadow transition-transform", checked ? "translate-x-5.5" : "translate-x-0.5")} />
      </button>
    </div>
  );
}

export function Checkbox({ label, className, ...rest }: React.InputHTMLAttributes<HTMLInputElement> & { label: React.ReactNode }) {
  return (
    <label className={cn("inline-flex cursor-pointer items-center gap-2 text-sm text-ink-800", className)}>
      <input type="checkbox" className="size-4 rounded border-ink-300 accent-brand-600" {...rest} />
      {label}
    </label>
  );
}
