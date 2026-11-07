export type ShiftStatus = "scheduled" | "in_progress" | "completed" | "missed" | "cancelled";

export type ShiftType = { id: number; name: string; start_time: string; end_time: string; color: string; branch_id: number | null; is_active: boolean };

export type Delegation = {
  id: number; shift_id: number; from_user_id: number; to_user_id: number; reason: string; emergency: boolean;
  status: "pending" | "accepted" | "declined" | "cancelled"; response_note: string | null; responded_at: string | null; created_at: string;
  from_user?: { id: number; name: string }; to_user?: { id: number; name: string }; shift?: Shift;
};

export type Handover = {
  id: number; shift_id: number; next_shift_id: number | null; from_user_id: number; to_user_id: number;
  cash_counted: string | null; notes: string | null; status: "pending" | "accepted"; receiver_comment: string | null; cash_confirmed: string | null;
  accepted_at: string | null; created_at: string; from_user?: { id: number; name: string }; to_user?: { id: number; name: string }; shift?: Shift;
};

export type Shift = {
  id: number; branch_id: number; shift_type_id: number | null; user_id: number;
  starts_at: string; ends_at: string; status: ShiftStatus; started_at: string | null; ended_at: string | null; notes: string | null; cancel_reason: string | null;
  user?: { id: number; name: string }; type?: { id: number; name: string; color: string } | null; branch?: { id: number; name: string };
  delegations?: Delegation[]; handover?: Handover | null; received_handover?: Handover | null;
};

export const SHIFT_STATUS_LABELS: Record<ShiftStatus, string> = {
  scheduled: "Planned", in_progress: "Working now", completed: "Done", missed: "Missed", cancelled: "Cancelled",
};
export const SHIFT_STATUS_TONES: Record<ShiftStatus, "neutral" | "green" | "saffron" | "red" | "blue"> = {
  scheduled: "blue", in_progress: "green", completed: "neutral", missed: "red", cancelled: "red",
};
export const SHIFT_COLORS: Record<string, string> = {
  blue: "bg-sky-100 text-sky-900 ring-sky-200", green: "bg-emerald-100 text-emerald-900 ring-emerald-200", amber: "bg-amber-100 text-amber-900 ring-amber-200",
  purple: "bg-violet-100 text-violet-900 ring-violet-200", red: "bg-rose-100 text-rose-900 ring-rose-200", gray: "bg-ink-100 text-ink-800 ring-ink-200",
};
export const WEEKDAYS = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];

export const hhmm = (iso: string) => {
  const d = new Date(iso);
  return `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
};
export const SHIFT_COLOR_LABELS: Record<string, string> = { blue: "Blue", green: "Green", amber: "Amber", purple: "Purple", red: "Red", gray: "Grey" };
