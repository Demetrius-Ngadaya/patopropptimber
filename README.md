# PatoPro

Point of sale, stock, accounting, HR/payroll and an online shop for retailers **and** wholesalers:
general shops, supermarkets, pharmacies, butcheries, hardware stores and distributors.
English and Kiswahili, light and dark mode.

```
mauzopro/
├── backend/    Laravel 12 REST API (PHP 8.2+, MySQL / MariaDB)
└── frontend/   Next.js 16 web app (staff back office + online shop)
```

The browser never talks to Laravel directly. Next.js keeps the login token in an httpOnly cookie
and forwards requests to the API from the server (`/api/backend/*`), so page scripts can't read the token.

---

## 1. Run it on your computer

Requirements: PHP 8.2+ with `pdo_mysql`, `mbstring`, `gd`, `zip`, `intl`, `bcmath`; Composer 2;
MySQL 8 or MariaDB 10.6+; Node.js 20+.

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# edit .env: DB_*, APP_URL=http://127.0.0.1:8000, FRONTEND_URL=http://localhost:3000,
#            CORS_ALLOWED_ORIGINS=http://localhost:3000, APP_ENV=local, APP_DEBUG=true
php artisan migrate --seed               # prints the admin password once if SEED_ADMIN_PASSWORD is empty
php artisan db:seed --class=DemoSeeder   # optional sample products, customers and sales
php artisan storage:link
php artisan serve                        # http://127.0.0.1:8000
```

Tests use an in-memory SQLite database; nothing touches MySQL:

```bash
php artisan test        # 82 tests: sales, credit, savings, double-entry ledger, payroll, AI, security, translations
```

If MySQL says `Access denied for user`, the `DB_USERNAME` / `DB_PASSWORD` in `.env` don't match a
MySQL user. Create one:

```sql
CREATE DATABASE mauzopro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mauzopro'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT ALL PRIVILEGES ON mauzopro.* TO 'mauzopro'@'localhost';
```

### Frontend

```bash
cd frontend
npm install
cp .env.example .env.local      # API_URL=http://127.0.0.1:8000/api/v1, COOKIE_SECURE=false for http://localhost
npm run dev                     # http://localhost:3000
```

Log in at `http://localhost:3000/login` with username `admin` and the seeded password.
You are asked to choose a new password straight away.

Useful checks:

```bash
npm run lint
npm run i18n:check      # fails if any screen text has no Kiswahili translation
npm run build
```

---

## 2. Deploy on DirectAdmin (or any Linux server)

This installation is hosted at **`pptimber.co.tz`**, with two addresses:

| Address | Runs | Notes |
|---|---|---|
| `https://pptimber.co.tz` (and `www.`) | the website (Next.js) | back office, shop, `/app` download page |
| `https://api.pptimber.co.tz` | the API (Laravel) | also serves the mobile app download (`/api/v1/public/app/download`) |

Create the `api` sub-domain in DirectAdmin and turn on SSL for both. (Elsewhere in this guide,
`yourshop.co.tz` means `pptimber.co.tz`.)

### Backend (api sub-domain)

1. Upload `backend/` to e.g. `~/domains/api.yourshop.co.tz/mauzopro` and point the sub-domain's
   document root to its `public/` folder.
2. Select PHP 8.2+ for the domain.
3. SSH in and run:
   ```bash
   composer install --no-dev --optimize-autoloader
   cp .env.example .env && php artisan key:generate
   # fill in .env (see below)
   php artisan migrate --seed --force
   php artisan storage:link
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
4. `storage/` and `bootstrap/cache/` must be writable by the web user (`chmod -R 775`).
5. Cron (DirectAdmin → Cron Jobs), every minute:
   ```
   * * * * * cd /home/USER/domains/api.yourshop.co.tz/mauzopro && php artisan schedule:run >> /dev/null 2>&1
   ```
   | When | Command | What it does |
   |---|---|---|
   | 23:00 daily, Sunday, month end, 31 Dec | `mauzo:send-report daily/weekly/monthly/yearly` | sales reports (bell, SMS, WhatsApp, email + PDF) |
   | 07:30 daily | `mauzo:daily-alerts` | stock and overdue-debt summaries (bell + chosen channels) |
   | 09:00 daily | `mauzo:debt-reminders` | reminders to customers with overdue credit (SMS / WhatsApp / email) |
   | 01:30 daily | `mauzo:backup` | database backup |
   | Monday 02:00 | `mauzo:score-credit` | AI credit scores for every credit customer |
   | 1st of month 02:30 | `mauzo:depreciate` | posts depreciation of fixed assets |
6. Turn on SSL for the sub-domain (Let's Encrypt).

Important `.env` values in production:

| Key | Value |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` (HTTPS is then enforced for generated URLs) |
| `APP_URL` | `https://api.pptimber.co.tz` |
| `FRONTEND_URL` | `https://pptimber.co.tz` |
| `CORS_ALLOWED_ORIGINS` | `https://pptimber.co.tz,https://www.pptimber.co.tz` |
| `TRUSTED_PROXIES` | IP of the server running Next.js (`127.0.0.1` if same server), so audit logs record the real client IP |
| `DB_*` | your database |
| `MAIL_*` | SMTP for password resets and emailed reports |
| `SMS_ENABLED`, `SMS_API_TOKEN`, `SMS_SENDER_ID` | Webline Africa SMS. Keep `false` until you have a fresh token |
| `AI_PROVIDER` + its key | optional, turns on the AI assistant, written weekly summaries and AI product descriptions. Free: `groq` (default), `gemini`, `openrouter`, `ollama` (see §5) |
| `AI_MODEL`, `AI_FAST_MODEL` | optional model overrides; empty uses the provider's default |
| `SEED_ADMIN_PASSWORD` | optional; otherwise a random password is printed once |

### Frontend (main domain)

```bash
cd frontend
npm ci
npm run build
```

`next.config.ts` uses `output: "standalone"`, so you only need to upload:

```
.next/standalone/        → the app folder
.next/static/            → copy into .next/standalone/.next/static
public/                  → copy into .next/standalone/public
```

Start it with Node 20+ (DirectAdmin → "Setup Node.js App", or PM2):

```bash
API_URL=https://api.pptimber.co.tz/api/v1 \
APP_URL=https://pptimber.co.tz \
COOKIE_SECURE=true \
PORT=3000 node server.js
```

Then proxy the main domain to port 3000. If both apps run on the same server you can point
`API_URL` at the local API virtual host to skip the public internet.

### Mobile app download (no Play Store)

The Android app is offered from the website itself:

- **`https://pptimber.co.tz/app`** (also `/pakua`) — public download page with install steps and a QR code.
- A **"Download the PatoPro app"** card under the login form and a link in the shop footer appear
  automatically once a version is published.
- The app checks the same address on start and shows *"New version available"*; a version marked
  **required** blocks the old app until it is updated (offline sales stay safe on the phone).

Releasing a version:

1. Build the APK: `cd mobile && npx eas-cli@latest build -p android --profile production`
   (the production profile builds an **.apk** pointed at `https://api.pptimber.co.tz`; use
   `--profile production-aab` only if you ever publish to the Play Store). Note the **version** and
   the **versionCode** EAS prints.
2. Download the `.apk` from EAS, then in **Administration → Mobile app downloads → Release a new
   version** upload it, enter the version (e.g. `1.1.0`) and the build number (= versionCode), write
   what's new and publish. Or paste the EAS download link instead of uploading.
3. Uploads go in small pieces (up to 3 MB, smaller automatically when PHP's `upload_max_filesize`
   is lower), so the hosting's default PHP limits are fine. The APK is stored privately in `backend/storage/app/private/app-releases/`
   — include that folder in backups. Only staff with the permission *Mobile app downloads* (admins
   by default) can upload.

---

## 3. First steps after installing

1. **Settings → Business**: name, logo (shown in a circle everywhere), currency, phone, address, TIN, default language.
2. **Login pictures** (Administration): upload the photos that slide on the left of the login page.
3. **Settings → Security**: session timeouts, failed logins before lock, minimum password length,
   and the roles that **must** use two-step login (recommended: `super-admin,admin,accountant`).
4. **My profile → Two-step login**: set it up for your own account first.
5. **Branches**, **Roles & permissions**, **Staff users**.
6. **Categories, units, products** (or import from Excel). Add **packs** (carton, dozen, bale) and
   **wholesale prices** on each product for wholesale selling.
7. **Settings → Accounting**: VAT rate and the accounts that receive cash, bank and mobile money.
8. **Settings → Payroll & HR**: check PAYE bands, NSSF, SDL, WCF and health insurance rates
   against the current TRA / NSSF / WCF rates. **They change; the defaults are a starting point only.**
9. **People → Departments & positions, Employees**; **Leave → Leave types / Public holidays** (Tanzanian defaults are seeded).
10. **Settings → Online shop**: turn the shop on, delivery fee, minimum order, self-registration.
11. **Settings → Messages**: tick the ways of sending (SMS, WhatsApp, email), enter the numbers and emails
    that receive notifications and reports, and choose per notification how it is sent. SMS is always a short
    summary; email carries the full details with a PDF attached.
12. **Settings → Barcodes & QR codes**: switch barcodes / QR codes on or off, choose what a QR code holds
    and what customers see when they scan it.
13. **Settings → Appearance**: pick ready-made colours or your own main, accent and sidebar colour.
14. **Software Engineer** (optional, the person who sets the system up): create the account on the server with
    `php artisan mauzo:create-developer <username>`. It is invisible to every other user, super admins included,
    and opens **Administration → System modules** to switch whole modules off or on for this business.
15. **Online payments (optional)**: put `SNIPPE_API_KEY` (scopes `collection:read`, `collection:create`) and
    `SNIPPE_WEBHOOK_SECRET` in `backend/.env`, paste the webhook address shown in **Settings → Online payments**
    into the Snippe dashboard, press *Test the connection*, then switch it on for the till and/or the online shop.
16. **Settings → Optional features**: switch on **Uwakala** and **Shifts** if your business needs them
    (both are off on a new install). Then give the roles access on **Roles & permissions**.

---

## 4. Modules

**Selling**
- Point of sale with barcode scanner, camera scanning, held sales, split payments, receipts (58/80 mm, A4) and SMS receipts.
- Retail and wholesale: price level per sale, packs with their own barcode and price, automatic wholesale price from a minimum quantity.
- **Credit sales**: walk-in credit customers are registered on the spot (name, phone, residential address), registered customers are picked from the list; the expected repayment date is required. Full or partial repayments, each recording amount owed, paid, remaining, date and repayment date, with the full payment history until settled. Reschedule with audit trail.
- **Customer savings**: deposits, purchases from savings, withdrawals and reversals with running balances. A purchase above the savings balance automatically becomes a credit sale for the rest. One financial history per customer covers savings, debts and repayments.
- Online shop orders (pickup or delivery, paid on collection), seller allocations, branch transfers.

**Quotations, proforma invoices & invoices**
- Each has its own number series (QT, PF, IV), editor with live prices, PDF, and a customer link (`/d/…`) to view, download and accept or decline.
- Stages: quotation draft → sent → accepted / declined / expired → converted; proforma draft → awaiting payment → paid → closed; invoice draft → owing → overdue → fully paid → closed. Every step is in the document's history.
- Issuing an invoice records a credit sale (stock out, customer debt, books posted); part payments reduce it. A proforma is paid in full and becomes a completed sale.
- Sending: email carries the PDF, WhatsApp the link, SMS a short note that it was sent.
- Settings → Invoices & quotations: title, validity / due days, terms, footer, extra columns and sections per document type, bank details, and a matrix of which company details (logo, address, phone, WhatsApp, email, website, TIN, VRN, bank) appear on receipts, invoices, quotations, proformas, statements, reports and emails.
- Reports: quotations (win rate), proformas, invoices and invoice aging.

**Customers & promotions**
- Customer insights: VIP, frequent, new, coming less, stopped coming and occasional customers, best and most frequent lists. Thresholds in Settings → Customer insights.
- Promotions (percentage or amount, on everything / categories / products, for all customers / groups / chosen customers, dates, minimum purchase, cap, uses per customer). Cashiers apply them at the till with one tap. Customers are told by SMS, WhatsApp or email with a PDF flyer.

**Automatic notifications** (Administration → Automatic notifications): what to report (sales summary, stock alerts, credit sales, overdue debts, collections, expenses, invoices, quotations, customers), which roles get it (bell + their own phone/email), the company's notification contacts and extra numbers/emails, channels, schedule (hourly, daily, weekly, monthly, once) and editable SMS/WhatsApp/email wording with placeholders and defaults. Runs from the scheduler (`mauzo:notification-rules`, every minute).

**Deliveries**
- Delivery person role. Cashiers and sellers give an online order or a till sale to a delivery person.
- Fee = base fee + price per km × road distance from the shop (Settings → Delivery: shop location on a map, price per km, minimum, furthest distance, rounding). Branches can have their own location. Customers pin their door on the map at checkout and see the fee.
- The delivery person sees the customer's exact location with directions, shares their live position while on the way, can give a discount on the delivery fee, and marks it delivered. The customer follows on a tracking page (`/t/…`), chats both ways, and marks it received.

**Payments by phone (Snippe)**
- The admin switches online payments on or off, separately for the till and for online-shop orders (Settings → Online payments).
- At the till the cashier picks *Pay by phone*, types the customer's number and the customer gets a USSD prompt (M-Pesa, Airtel Money, Mixx by Yas, HaloPesa). The sale can only be completed once Snippe confirms the payment; each payment is used once, for its exact amount.
- In the online shop the customer can pay when placing the order or later from the order page; the money counts automatically when the order is handed over. A paid order cannot be cancelled by the customer; staff who cancel one are told to refund.
- Results arrive by signed webhook (`POST /api/v1/webhooks/snippe`, HMAC-SHA256 checked, 5-minute window, duplicates ignored); while someone waits the system also asks Snippe, so it works even before the webhook is reachable. *Payments by phone* lists every request, fees, failures and money paid but not used; there is also a report.

**Purchase planning**
- Plan what to buy (supplier, expected date, products, quantities, expected cost), then move it through draft → pending (approved and ordered) → partly received → received, or cancelled / closed. Goods arrive in one or more deliveries; each becomes a purchase linked to the plan and adds stock.
- Credit plans set the days to pay. Every credit purchase (planned or not) has a pay-by date, a reminder day and the ways to remind: SMS (short), WhatsApp (fuller) or email (every detail with a PDF). Reminders go on the reminder day and again on the due date while money is owed (`mauzo:purchase-reminders`, daily 08:00). Purchases list: still owed, overdue and due-this-week tabs. Reports: credit purchases to pay, purchase plans.

**Uwakala** (optional): one line per mobile-money or bank agency (M-Pesa, Mixx by Yas, Airtel Money, HaloPesa, NMB, CRDB, NBC …). Each morning the float on every line and the cash in hand are counted, again at night; the system shows morning and evening totals and any shortage or excess after capital put in or taken out (managers are warned above a set amount). Monthly commissions per line. Reports: uwakala days and commissions.

**Notes & messages**
- My notes: personal notes with colours, pin to the top, reminder time, PDF and picture attachments, and sharing with colleagues who can read or change.
- Messages from management: the admin writes to everyone, some roles or chosen people (optionally also by SMS/WhatsApp/email). Reminders and announcements pop up right after login until the person presses “I have read it”; the sender sees who has read.

**Shifts** (optional): shift types, a weekly rota, staff start and end their own shift, hand over to the next person with notes and the cash counted (the receiver confirms), and ask a colleague to cover in an emergency (the colleague accepts or declines; managers are told). Missed shifts are marked automatically (`mauzo:personal-reminders`, every minute, also sends note reminders).

**Menu search**: the search box at the top of the sidebar (Ctrl K or /) finds pages by English or Kiswahili name; arrows and Enter open them.

**Stock**: per-branch quantities, expiry batches sold first-expiring-first, adjustments and stock counts, a stock ledger that can't be edited.

**Accounting & finance** (double entry; every sale, purchase, expense, payroll and savings movement posts automatically)
- Chart of accounts, general ledger, manual and quick journals, reversals.
- Profit and loss (with comparison), balance sheet, cash flow, trial balance, VAT return, aged receivables and payables.
- Budgets vs actual, fixed assets with monthly depreciation and disposal, bank / mobile-money reconciliation, financial year closing and reopening.

**HR & payroll**
- Employees (encrypted ID, TIN and bank numbers; salaries visible only with permission), documents stored privately, suspension and termination.
- Departments, positions with salary bands, attendance register and self clock-in, leave types, balances (pro-rata, carry forward), requests and approvals, public holidays.
- Payroll with PAYE, NSSF, SDL, WCF, health insurance, allowances, deductions, unpaid days and salary-advance recovery. Prepared by one person and approved by another; posted to the books; PDF payslips; statutory remittances.
- Employee self-service: payslips, leave, clock in/out, advance requests.

**AI** (in-house models work without any API key)
- Demand forecasting and a reorder planner (trend + weekday pattern, safety stock, stock-out dates).
- Fraud and anomaly alerts: cashier cancellations and discounts, below-cost sales, stock losses, unusual hours, back-dated sales, weak days.
- Credit scoring (A–E) with a suggested limit and the reasons behind each score.
- Smart insights on the dashboard.
- With a language model (free Groq, Gemini, OpenRouter or local Ollama; or paid Claude/OpenAI): an assistant that answers questions in English or Kiswahili from live figures (read-only, limited to what the user may see, with a monthly token budget and a daily question limit), written weekly summaries and English/Kiswahili product descriptions.

**Security**
- httpOnly SameSite token cookie, CSRF checks, strict security headers, `Cache-Control: no-store` on API responses.
- Two-step login (TOTP authenticator app, one-time recovery codes, replay protection), required per role.
- Uniform login errors (no account enumeration), account lock, rate limits on login, two-step codes, the API, the shop and exports.
- Admin-set inactivity timeout enforced on the server, optional single session per user.
- Permission check on every API route; the UI hides what a user can't use. Soft deletes everywhere with restore.
- Audit logs: data changes with before/after values, security events, every write request (secrets removed), login history, SMS log, document and payslip downloads.

**Languages & theme**: English / Kiswahili and light / dark / device theme, switchable on every page and remembered on the account.

### Code layout

```
backend/app/
  Http/Controllers/Api/V1/{Auth,Core,Catalog,Inventory,Sales,Payments,Crm,Marketing,Delivery,Purchasing,Agency,Notes,Shifts,Finance,Hr,Reports,Ai,Shop}
  Services/{Core,Catalog,Inventory,Sales,Payments,Documents,Marketing,Notifications,Delivery,Crm,Purchasing,Agency,Notes,Shifts,Finance,Hr,Reports,Ai}   business rules live here
  Models/…  Support/ (permission catalogue, settings definitions, TOTP)  Console/Commands
backend/routes/api/{core,catalog,inventory,sales,payments,documents,marketing,delivery,crm,purchasing,agency,notes,shifts,finance,hr,reports,ai}.php
backend/lang/sw.json, lang/sw/*.php           Kiswahili API messages and validation

frontend/src/
  app/            routes only (admin back office, (shop) online shop, login, API gateway)
  features/       one folder per business area: components + types (sales, payments, credit, savings, agency, shifts, messages, purchase-plans, accounting, hr, ai, …)
  components/     shared UI kit, layout, providers
  lib/            API client, auth, i18n (dictionaries/sw.ts), theme, formatting
  scripts/check-translations.cjs
```

---

## 5. Notes

- **AI provider (free)**: forecasting, anomaly alerts, credit scores and insights never need a key.
  For the assistant, summaries and product descriptions pick a provider in `backend/.env`:

  | `AI_PROVIDER` | Key | Free allowance | Notes |
  |---|---|---|---|
  | `groq` (default) | `GROQ_API_KEY` from console.groq.com/keys | about 1,000 requests/day on `openai/gpt-oss-120b` | fastest; recommended |
  | `gemini` | `GEMINI_API_KEY` from aistudio.google.com/apikey | free Flash models, limits shown in AI Studio | free-tier prompts may be used by Google to improve its products; not for EU/UK users |
  | `openrouter` | `OPENROUTER_API_KEY` | 50 requests/day on `:free` models | many models to choose from |
  | `ollama` | none | unlimited, private | runs on your own PC/VPS (8 GB+ RAM); `ollama pull qwen3:8b`, set `OLLAMA_BASE_URL` if not on the same machine |
  | `anthropic` / `openai` | `ANTHROPIC_API_KEY` / `AI_API_KEY` | paid | highest quality |

  Free limits are set by each provider and change; check their pages. After changing `.env` run
  `php artisan config:cache`. Keys stay on the server; the browser never sees them. The assistant needs a
  model with tool (function) calling: the defaults have it.
- **WhatsApp** uses Meta's WhatsApp Business Cloud API: set `WHATSAPP_ENABLED`, `WHATSAPP_TOKEN`,
  `WHATSAPP_PHONE_NUMBER_ID` and `WHATSAPP_TEMPLATE` in `backend/.env`. A business can only start a WhatsApp
  conversation with an approved template: create a *Utility* template whose body is a single variable `{{1}}`
  and put its name in `WHATSAPP_TEMPLATE`. Without it, messages only reach people who wrote to your number in
  the last 24 hours.
- **Email** needs SMTP in `backend/.env` (`MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
  `MAIL_FROM_ADDRESS`). Settings → Messages shows which ways of sending are set up on the server.
- **Notifications** (the bell) are shown by permission and branch: a cashier sees stock alerts for their branch,
  an accountant sees reports and overdue debts, HR sees leave requests. Morning summaries run at 07:30
  (`mauzo:daily-alerts`); old notifications are removed after 60 days.
- **Payroll rates** are editable in Settings → Payroll & HR. Verify them with TRA / NSSF / WCF before each financial year.
- No online payment: orders are paid on collection or delivery and recorded at the till.
- After adding a new permission in code run `php artisan mauzo:sync-permissions`.
- **Permissions** come from the role, and can be adjusted for one person: Staff users → *Personal permissions*
  (Allow / Deny per permission). This does not change anyone else with the same role. Switched-off modules
  remove their permissions for everyone.

## 6. Before going live

The old system's `config_sms.php` contained a live SMS API token and a Gmail app password.
**Revoke both** (Webline Africa dashboard and Google account → App passwords) and put fresh
ones only in `backend/.env`. Never commit `.env`; never upload `vendor/`, `node_modules/` or `.env` files in a zip.
