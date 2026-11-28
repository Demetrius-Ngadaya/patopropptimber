# PatoPro platform (many businesses, one app)

One codebase runs two ways, chosen in `backend/.env`:

| `PATOPRO_MODE` | Used for | Data |
|---|---|---|
| `single` (default) | PP Timber, Dablos, Metro… on their own domains | one database |
| `platform` | patopro.shezoni.com/&lt;business&gt; | a central database + one database per business |

Code never checks the mode. It asks:

- `business()` – the current business: `name()`, `state()`, `canWrite()`, `daysLeft()`, `toArray()`.
- `plan()` – its package: `allows('voice')`, `limit('users')`, `ensureWithin('users', $count)`.

Platform-only code lives in `backend/app/Platform/` and `backend/database/migrations/platform/`.

## Step 1

- **Central tables:** `platform_plans` (packages) and `platform_businesses` (address, owner, package, database, trial/paid dates).
- **Business switch:** every API request on the platform carries `X-Business: <slug>` (or `?business=<slug>` for gateway callbacks). The API then uses that business's database, cache folder and file folders.
- **Subscription state from the dates:** trial → active → grace (full use) → read-only (view, export, pay) → archived. Suspended is set by hand.
- **Read-only:** changes are refused with `402 SUBSCRIPTION_READ_ONLY`. Sign-in, reports, exports and payment still work.
- **Packages:** a module left out of a package is switched off like on the Modules screen. `feature:voice` and `feature:online_shop` guard the voice and shop routes. Users, branches and products are counted against the package's limits.
- **Single mode:** the same limits can be set in `.env` (`PATOPRO_FEATURES`, `PATOPRO_LIMIT_*`). By default everything is allowed with no limits, so existing installs do not change.
- **New endpoint:** `GET /api/v1/business` – the business, its state and its package (for banners and menus).

## Step 2: sign-up, backend

- **Packages:** Starter 10,000 · Msingi 15,000 · **Biashara 35,000 (most popular)** · Professional 55,000 · Premium 75,000 TSh a month (a year = 10 months). All have a 14-day free trial.
- **Sign-up API (`/api/v1/platform/…`):**
  - `GET plans`
  - `POST signup/check-address`
  - `POST signup` (sends an SMS code)
  - `POST signup/{id}/resend`
  - `POST signup/{id}/verify`
  - `GET signup/{id}`
- **Setting up a new business:**
  1. The database is created through the DirectAdmin API.
  2. It is filled by copying the **template database**, which takes seconds.
  3. The owner becomes the administrator.
  4. A welcome SMS is sent.
  If the setup is slow or fails, `platform:provision` (every minute) finishes it.
- **Owners sign in with their phone number,** typed any common way (0712…, 255712…, +255…). This works on single installs too.
- **One cron line:** it runs the platform's own tasks, then each business's tasks in a separate process.

## Step 4 (this release): paying for packages, backend

- **Gateways:**
  - **AzamPay** through the SHEZONI payment service (the same as for domains, hosting and SMS), using the `AZAMPAY_*` keys.
  - **Snippe**, using the `SNIPPE_*` keys.
- **The Software Engineer chooses which gateways are offered:**
  ```bash
  php artisan platform:billing                               # show
  php artisan platform:billing --enable=snippe --default=azampay --choice=no
  php artisan platform:billing --disable=snippe
  ```
  With `--choice=no`, businesses pay only with the default gateway. With `--choice=yes`, they choose among the offered ones.
- **Business API, while signed in (`/api/v1/platform/billing…`):**
  - `GET billing`: state, packages, gateways and networks, past payments.
  - `POST billing/pay`: sends the USSD push.
  - `GET billing/payments/{number}`: the page asks this until the payment is settled.

  These also work while a business is read-only.
- **Callbacks:**
  - AzamPay: `/api/v1/platform/webhooks/azampay/<AZAMPAY_CALLBACK_SECRET>`. The secret is in the address, as in the SHEZONI hosting portal.
  - Snippe: `/api/v1/platform/webhooks/snippe` (signed).

  Every payment is **checked with the gateway** before it counts, whatever the callback says.
- **Networks for AzamPay:** detected from the number: Airtel (068/069/078), Tigo / Mixx by Yas (065/067/071/077) and HaloPesa (061/062). M-Pesa numbers are refused with a clear message.
- **When a payment is confirmed:**
  - The package is extended by 1 month, or 12 for yearly. Yearly costs 10 months' price.
  - It counts from the end of the current paid or trial period, so paying early loses no days. If the business has already expired, it counts from today.
  - A receipt goes by SMS.
- **Cash or bank payments:**
  ```bash
  php artisan platform:pay duka-la-juma --plan=biashara --cycle=yearly --note="CRDB 123"
  ```
- **Scheduled tasks:**
  - **Every 5 minutes:** payments still waiting are checked with their gateway, and expire after 30 minutes.
  - **Daily at 09:00:** SMS reminders 7, 3 and 1 days before the end, on the last day, and once when the business becomes read-only.

Not yet: the website pages (pricing, sign-up, the billing page), website addresses `/<business>/…`, and mobile phone sign-in.

## Commands (platform mode)

```bash
php artisan platform:install                      # central tables + example packages (edit their prices!)
php artisan platform:business:create duka-la-juma "Duka la Juma" --plan=biashara --owner="Juma Mussa" --phone=0712345678
php artisan platform:template                     # create/refresh the template new businesses are copied from
php artisan platform:provision                    # finish setups that did not complete (the scheduler runs it)
php artisan platform:list                         # businesses, package, state, end date
php artisan platform:billing                      # which payment gateways are offered
php artisan platform:pay <business> --cycle=…     # record a cash/bank payment
php artisan platform:reminders --dry-run          # see which reminders would go out today
php artisan platform:migrate                      # central + every business (deploy.sh runs this)
php artisan platform:run schedule:run             # scheduled tasks for every business (the one cron line)
php artisan platform:run cache:clear --business=duka-la-juma
PATOPRO_BUSINESS=duka-la-juma php artisan tinker  # any command for one business
```

Run the same create command again if a setup failed (for example the database did not exist yet).

## Setting up patopro.shezoni.com (when we get there)

1. **In DirectAdmin → MySQL**, create:
   - the central database and its user;
   - one "business" database (for example `shezonic_ppbiz`) with its user. That user is then given every business database.
2. **In DirectAdmin → Login Keys**, create a key that allows only `CMD_API_DATABASES`. Put it in `DIRECTADMIN_LOGIN_KEY`.
3. **Copy the code:** `git clone … ~/platform`. In `backend/.env`, set `PATOPRO_MODE=platform`, the `PLATFORM_*` lines (see `.env.example`), and `DB_DATABASE` = the central database.
4. **Install:** `php artisan platform:install`, then `php artisan platform:template`.
5. **Cron:** one line for the whole platform. `PHP_BINARY` makes the tasks it starts use the same PHP:
   `* * * * * cd /home/shezonic/platform/backend && PHP_BINARY=/home/shezonic/bin/php /home/shezonic/bin/php artisan schedule:run >> storage/logs/cron.log 2>&1`

## Tests

```bash
php artisan test --filter=Platform
```
`SingleModeTest` proves single installs are unchanged. `PlatformModeTest` runs four businesses on separate SQLite files and checks:
- each business reads only its own database;
- packages switch modules on and off and enforce limits;
- grace and read-only work;
- a suspended business is closed.
