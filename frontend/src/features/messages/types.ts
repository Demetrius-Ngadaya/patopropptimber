export type MessageKind = "message" | "reminder" | "announcement";

export type StaffMessage = {
  id: number;
  kind: MessageKind;
  priority: "normal" | "high";
  title: string;
  body: string;
  audience: "all" | "roles" | "users";
  requires_ack: boolean;
  show_from: string | null;
  expires_at: string | null;
  created_at: string;
  sender?: { id: number; name: string } | null;
  recipients_count?: number;
  read_count?: number;
  acknowledged_count?: number;
};

export type InboxRow = { id: number; staff_message_id: number; read_at: string | null; acknowledged_at: string | null; message: StaffMessage };

export const KIND_LABELS: Record<MessageKind, string> = { message: "Message", reminder: "Reminder", announcement: "Announcement" };
export const KIND_TONES: Record<MessageKind, "blue" | "saffron" | "navy"> = { message: "blue", reminder: "saffron", announcement: "navy" };
