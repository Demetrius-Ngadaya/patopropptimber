import type { Channel } from "@/features/messaging/channels";

export type NotificationRule = {
  id: number;
  name: string;
  source: string;
  branch_id: number | null;
  channels: Channel[];
  role_ids: number[] | null;
  send_to_role_users: boolean;
  include_company_contacts: boolean;
  extra_phones: string | null;
  extra_whatsapp: string | null;
  extra_emails: string | null;
  frequency: Frequency;
  time: string;
  weekday: number | null;
  month_day: number | null;
  run_at: string | null;
  period: string;
  skip_when_empty: boolean;
  sms_template: string | null;
  whatsapp_template: string | null;
  email_subject: string | null;
  email_template: string | null;
  is_active: boolean;
  next_run_at: string | null;
  last_run_at: string | null;
  last_result: Record<string, { recipients: number; sent: boolean }> | null;
};

export type Frequency = "hourly" | "daily" | "weekly" | "monthly" | "once";

export type Source = {
  key: string;
  label: string;
  variables: Record<string, string>;
  defaults: { sms: string; whatsapp: string; subject: string; email: string };
};

export type RulePreview = {
  sms: string;
  whatsapp: string;
  email_subject: string;
  email_intro: string;
  variables: Record<string, string>;
  empty: boolean;
  recipients: { sms: string[]; whatsapp: string[]; emails: string[] };
};

export const FREQUENCY_LABELS: Record<Frequency, string> = {
  hourly: "Every hour",
  daily: "Every day",
  weekly: "Every week",
  monthly: "Every month",
  once: "Once",
};

export const PERIOD_LABELS: Record<string, string> = {
  today: "Today",
  yesterday: "Yesterday",
  last_7_days: "Last 7 days",
  this_week: "This week",
  last_week: "Last week",
  this_month: "This month",
  last_month: "Last month",
  last_30_days: "Last 30 days",
};

export const WEEKDAY_LABELS = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
