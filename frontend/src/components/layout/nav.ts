import {
  Activity, CircleHelp, ArrowLeftRight, BadgePercent, BellRing, CalendarClock, ClipboardCheck, Inbox, Megaphone as MegaphoneIcon, NotebookPen, Smartphone, Users2, Bike, Crown, FileCheck2, FileText, Megaphone, Blocks, BarChart3, BookOpenText, Boxes, Briefcase, Building2, CalendarCheck, CalendarDays, ClipboardList,
  Coins, FileClock, FileSpreadsheet, FolderTree, Gauge, HandCoins, History, Images, Landmark, LayoutGrid, LineChart, ListTree,
  MessageSquareText, PackageSearch, PiggyBank, Receipt, Ruler, ScaleIcon, ScanBarcode, Settings, ShieldAlert, ShieldCheck,
  ShoppingBag, ShoppingCart, SlidersHorizontal, Sparkles, Target, Truck, UserCog, UserRound, Users, Wallet, Warehouse,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";

/** keywords: extra words the menu search should match (English; Swahili comes from the translated label). */
export type NavItem = { href: string; label: string; icon: LucideIcon; perm: string[]; keywords?: string };
export type NavGroup = { label: string; items: NavItem[] };

/**
 * The back-office menu. Labels are English keys translated at render time
 * (AdminShell), so this file has no hooks and can be imported anywhere.
 * Each entry shows only when the user has at least one of its permissions.
 */
export const navigation: NavGroup[] = [
  {
    label: "Overview",
    items: [
      { href: "/admin", label: "Dashboard", icon: Gauge, perm: ["dashboard.view"] },
      { href: "/admin/analysis", label: "Business analysis", icon: BarChart3, perm: ["dashboard.view_financials"] },
      { href: "/admin/ai/assistant", label: "AI assistant", icon: Sparkles, perm: ["ai.assistant"] },
      { href: "/admin/notes", label: "My notes", icon: NotebookPen, perm: ["@staff"], keywords: "memo remember pin kumbukumbu" },
      { href: "/admin/inbox", label: "Messages from management", icon: Inbox, perm: ["@staff"], keywords: "announcement reminder inbox tangazo" },
      { href: "/admin/shifts/mine", label: "My shifts", icon: CalendarClock, perm: ["shifts.own"], keywords: "zamu shift handover cover" },
    ],
  },
  {
    label: "Uwakala",
    items: [
      { href: "/admin/agency", label: "Uwakala today", icon: Smartphone, perm: ["agency.view"], keywords: "wakala float mpesa tigo airtel halopesa nmb crdb nbc opening closing" },
      { href: "/admin/agency/days", label: "Uwakala days", icon: CalendarDays, perm: ["agency.view"], keywords: "wakala history" },
      { href: "/admin/agency/commissions", label: "Commissions", icon: Coins, perm: ["agency.view", "agency.commissions"], keywords: "kamisheni wakala" },
      { href: "/admin/agency/accounts", label: "Agency lines", icon: Settings, perm: ["agency.manage"], keywords: "wakala setup accounts" },
    ],
  },
  {
    label: "Selling",
    items: [
      { href: "/admin/pos", label: "Point of sale", icon: ScanBarcode, perm: ["pos.access"] },
      { href: "/admin/sales", label: "Sales", icon: ShoppingCart, perm: ["sales.view"] },
      { href: "/admin/credit-sales", label: "Credit sales", icon: HandCoins, perm: ["credit.view"] },
      { href: "/admin/online-payments", label: "Payments by phone", icon: Smartphone, perm: ["online_payments.view"], keywords: "online mobile money snippe m-pesa airtel mixx halopesa lipa kwa simu" },
      { href: "/admin/savings", label: "Customer savings", icon: PiggyBank, perm: ["savings.view"] },
      { href: "/admin/orders", label: "Orders", icon: ShoppingBag, perm: ["orders.view"], keywords: "online shop" },
      { href: "/admin/deliveries", label: "Deliveries", icon: Bike, perm: ["deliveries.view", "deliveries.assign", "deliveries.own"], keywords: "delivery courier rider boda track" },
      { href: "/admin/customers", label: "Customers", icon: Users, perm: ["customers.view"] },
    ],
  },
  {
    label: "Quotations & invoices",
    items: [
      { href: "/admin/documents/quotation", label: "Quotations", icon: FileText, perm: ["documents.view"], keywords: "quote estimate nukuu" },
      { href: "/admin/documents/proforma", label: "Proforma invoices", icon: FileClock, perm: ["documents.view"], keywords: "proforma advance payment" },
      { href: "/admin/documents/invoice", label: "Invoices", icon: FileCheck2, perm: ["documents.view"], keywords: "invoice bill tax invoice ankara" },
    ],
  },
  {
    label: "Customers & promotions",
    items: [
      { href: "/admin/customer-insights", label: "Customer insights", icon: Crown, perm: ["marketing.insights"], keywords: "vip best frequent lost declining loyal" },
      { href: "/admin/promotions", label: "Promotions", icon: Megaphone, perm: ["marketing.promotions"], keywords: "offer discount campaign ofa" },
      { href: "/admin/faqs", label: "Shop FAQs", icon: CircleHelp, perm: ["faqs.manage"], keywords: "faq questions answers maswali chatbot assistant" },
      { href: "/admin/visitors", label: "Website visitors", icon: Activity, perm: ["visitors.view"], keywords: "visitors online traffic wageni analytics" },
    ],
  },
  {
    label: "Stock",
    items: [
      { href: "/admin/products", label: "Products", icon: PackageSearch, perm: ["products.view"] },
      { href: "/admin/categories", label: "Categories", icon: FolderTree, perm: ["categories.view"] },
      { href: "/admin/units", label: "Units", icon: Ruler, perm: ["units.view"] },
      { href: "/admin/stock", label: "Stock alerts", icon: Warehouse, perm: ["stock.alerts"] },
      { href: "/admin/ai/reorder", label: "Reorder planner", icon: LineChart, perm: ["ai.forecast"] },
      { href: "/admin/stock/adjustments", label: "Adjustments", icon: SlidersHorizontal, perm: ["stock.view", "stock.adjust"] },
      { href: "/admin/stock/movements", label: "Stock ledger", icon: History, perm: ["stock.movements"] },
    ],
  },
  {
    label: "Buying & moving",
    items: [
      { href: "/admin/purchase-plans", label: "Purchase planning", icon: ClipboardCheck, perm: ["purchase_plans.view"], keywords: "order plan po" },
      { href: "/admin/purchases", label: "Purchases", icon: Truck, perm: ["purchases.view"], keywords: "credit supplier debt due" },
      { href: "/admin/suppliers", label: "Suppliers", icon: Boxes, perm: ["suppliers.view"] },
      { href: "/admin/transfers", label: "Branch transfers", icon: ArrowLeftRight, perm: ["transfers.view"] },
      { href: "/admin/allocations", label: "Seller allocations", icon: ClipboardList, perm: ["allocations.view", "allocations.view_own"] },
    ],
  },
  {
    label: "Accounting & finance",
    items: [
      { href: "/admin/expenses", label: "Expenses", icon: Wallet, perm: ["expenses.view"] },
      { href: "/admin/accounting/statements", label: "Financial statements", icon: FileSpreadsheet, perm: ["accounting.statements"] },
      { href: "/admin/accounting/accounts", label: "Chart of accounts", icon: ListTree, perm: ["accounting.view"] },
      { href: "/admin/accounting/journals", label: "Journals", icon: BookOpenText, perm: ["accounting.view"] },
      { href: "/admin/accounting/budgets", label: "Budgets", icon: Target, perm: ["accounting.budgets", "accounting.statements"] },
      { href: "/admin/accounting/fixed-assets", label: "Fixed assets", icon: Landmark, perm: ["accounting.fixed_assets", "accounting.view"] },
      { href: "/admin/accounting/reconciliations", label: "Bank reconciliation", icon: ScaleIcon, perm: ["accounting.reconcile"] },
      { href: "/admin/accounting/years", label: "Financial years", icon: CalendarDays, perm: ["accounting.close_year", "accounting.view"] },
      { href: "/admin/reports", label: "Reports", icon: LayoutGrid, perm: ["reports.sales", "reports.stock", "reports.purchases", "reports.credit", "reports.expenses", "reports.profit", "reports.performance", "reports.transfers"] },
    ],
  },
  {
    label: "People",
    items: [
      { href: "/admin/hr/employees", label: "Employees", icon: Briefcase, perm: ["employees.view"] },
      { href: "/admin/hr/departments", label: "Departments & positions", icon: Building2, perm: ["departments.view"] },
      { href: "/admin/hr/attendance", label: "Attendance", icon: CalendarCheck, perm: ["attendance.view"] },
      { href: "/admin/hr/leave", label: "Leave", icon: CalendarDays, perm: ["leave.view"] },
      { href: "/admin/hr/payroll", label: "Payroll", icon: Receipt, perm: ["payroll.view"] },
      { href: "/admin/hr/advances", label: "Salary advances", icon: Coins, perm: ["payroll.advances", "payroll.approve"] },
      { href: "/admin/hr/me", label: "My HR", icon: UserRound, perm: ["hr.self_service"] },
      { href: "/admin/shifts", label: "Shift rota", icon: Users2, perm: ["shifts.view", "shifts.manage"], keywords: "zamu schedule roster" },
    ],
  },
  {
    label: "Insights",
    items: [
      { href: "/admin/ai/anomalies", label: "Fraud & anomaly alerts", icon: ShieldAlert, perm: ["ai.anomalies"] },
      { href: "/admin/ai/credit-scores", label: "Credit scores", icon: Sparkles, perm: ["ai.credit_score"] },
    ],
  },
  {
    label: "Administration",
    items: [
      { href: "/admin/users", label: "Staff users", icon: UserCog, perm: ["users.view"] },
      { href: "/admin/roles", label: "Roles & permissions", icon: ShieldCheck, perm: ["roles.view"] },
      { href: "/admin/branches", label: "Branches", icon: Building2, perm: ["branches.view"] },
      { href: "/admin/audit", label: "Audit logs", icon: FileClock, perm: ["audit.activity", "audit.changes", "audit.logins"] },
      { href: "/admin/sms", label: "Messages", icon: MessageSquareText, perm: ["sms.send", "sms.logs"], keywords: "sms whatsapp email" },
      { href: "/admin/announcements", label: "Announcements to staff", icon: MegaphoneIcon, perm: ["messages.send"], keywords: "message reminder broadcast tangazo" },
      { href: "/admin/notification-rules", label: "Automatic notifications", icon: BellRing, perm: ["settings.notifications"], keywords: "alerts schedule daily report reminder" },
      { href: "/admin/expense-categories", label: "Expense categories", icon: BadgePercent, perm: ["expenses.manage_categories"] },
      { href: "/admin/mobile-app", label: "Mobile app downloads", icon: Smartphone, perm: ["mobile_app.manage"], keywords: "apk android download pakua update version" },
      { href: "/admin/login-slides", label: "Pictures: login & shop", icon: Images, perm: ["login_slides.view"], keywords: "slides hero banner picha" },
      { href: "/admin/settings", label: "Settings", icon: Settings, perm: ["settings.view"], keywords: "tin vrn logo receipt company business delivery fee" },
      { href: "/admin/system/modules", label: "System modules", icon: Blocks, perm: ["@developer"] },
    ],
  },
];
