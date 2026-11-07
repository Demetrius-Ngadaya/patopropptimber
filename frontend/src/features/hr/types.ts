export type Employee = {
  id: number;
  employee_no: string;
  user_id: number | null;
  first_name: string;
  middle_name: string | null;
  last_name: string;
  full_name: string;
  photo_url: string | null;
  gender: "male" | "female" | null;
  date_of_birth: string | null;
  marital_status: string | null;
  national_id?: string | null;
  phone: string | null;
  email: string | null;
  address: string | null;
  emergency_contact_name: string | null;
  emergency_contact_phone: string | null;
  emergency_contact_relation: string | null;
  branch_id: number | null;
  department_id: number | null;
  position_id: number | null;
  manager_id: number | null;
  employment_type: "permanent" | "contract" | "casual" | "intern";
  hire_date: string;
  probation_end_date: string | null;
  contract_end_date: string | null;
  status: "active" | "on_leave" | "suspended" | "terminated" | "resigned";
  exit_date: string | null;
  exit_reason: string | null;
  basic_salary?: string;
  payment_method: "bank" | "mobile_money" | "cash";
  bank_name?: string | null;
  bank_account_number?: string | null;
  mobile_money_number?: string | null;
  tin?: string | null;
  nssf_number?: string | null;
  nhif_number?: string | null;
  paye_exempt?: boolean;
  nssf_exempt?: boolean;
  notes: string | null;
  department?: { id: number; name: string } | null;
  position?: { id: number; title: string } | null;
  branch?: { id: number; name: string } | null;
  manager?: { id: number; first_name: string; last_name: string } | null;
  user?: { id: number; username: string; is_active: boolean } | null;
  components?: Array<{ id: number; name: string; code: string; type: "earning" | "deduction"; calculation: "fixed" | "percent"; amount: string; pivot: { amount: string | null; starts_on: string | null; ends_on: string | null } }>;
  documents?: Array<{ id: number; title: string; type: string; original_name: string; size: number; expires_on: string | null; created_at: string }>;
  deleted_at?: string | null;
};

export type LeaveBalance = { id?: number; leave_type_id: number; type?: string; entitled: string | number; carried_forward: string | number; adjustment: string | number; taken: string | number; remaining: number; leave_type?: { id: number; name: string; code: string; is_paid: boolean } };

export type LeaveRequest = {
  id: number; number: string; employee_id: number; leave_type_id: number; start_date: string; end_date: string; days: string;
  reason: string | null; status: "pending" | "approved" | "rejected" | "cancelled"; decision_note: string | null; decided_at: string | null; has_attachment: boolean;
  employee?: { id: number; employee_no: string; first_name: string; middle_name: string | null; last_name: string; full_name?: string };
  leave_type?: { id: number; name: string; code?: string };
  decider?: { id: number; name: string } | null;
  created_at: string;
};

export type PayrollRun = {
  id: number; number: string; period: string; period_start: string; period_end: string; status: "draft" | "approved" | "paid" | "cancelled";
  employees: number; gross: string; paye: string; nssf_employee: string; nssf_employer: string; sdl: string; wcf: string; nhif_employee: string; nhif_employer: string;
  other_deductions: string; advance_recovery: string; net: string; employer_cost: string; approved_at: string | null; paid_at: string | null; payment_method: string | null; notes: string | null;
  branch?: { id: number; name: string } | null; approver?: { id: number; name: string } | null; creator?: { id: number; name: string } | null;
};

export type Payslip = {
  id: number; payroll_run_id: number; employee_id: number; employee_name: string; position: string | null; department: string | null;
  basic_salary: string; days_payable: string; unpaid_days: string; unpaid_deduction: string;
  earnings: Array<{ name: string; amount: number }> | null; deductions: Array<{ name: string; amount: number }> | null;
  gross: string; taxable_income: string; paye: string; nssf_employee: string; nssf_employer: string; sdl: string; wcf: string; nhif_employee: string; nhif_employer: string;
  other_deductions: string; advance_recovery: string; net: string; payment_method: string | null; bank_name: string | null; account_hint: string | null;
  employee?: { id: number; employee_no: string };
  run?: { id: number; number: string; period: string; status: string; paid_at: string | null };
};

export type SalaryAdvance = {
  id: number; number: string; employee_id: number; amount: string; installments: number; monthly_deduction: string; recovered: string; outstanding: number;
  reason: string | null; status: "pending" | "approved" | "rejected" | "paid" | "recovered"; decision_note: string | null; paid_at: string | null; created_at: string;
  employee?: { id: number; employee_no: string; first_name: string; middle_name: string | null; last_name: string };
};

export const employeeName = (e?: { first_name: string; middle_name?: string | null; last_name: string; full_name?: string } | null) =>
  e ? e.full_name ?? [e.first_name, e.middle_name, e.last_name].filter(Boolean).join(" ") : "";

export const EMPLOYEE_STATUS_TONE = { active: "green", on_leave: "blue", suspended: "saffron", terminated: "red", resigned: "neutral" } as const;
export const RUN_STATUS_TONE = { draft: "saffron", approved: "blue", paid: "green", cancelled: "red" } as const;
