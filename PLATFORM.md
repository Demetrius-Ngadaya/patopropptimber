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

## Step 1 (this release)

- **Central tables:** `platform_plans` (packages) and `platform_businesses` (address, owner, package, database, trial/paid dates).
- **Business switch:** every API request on the platform carries `X-Business: <slug>` (or `?business=<slug>` for gateway callbacks). The API then uses that business's database, cache folder and file folders.
- **Subscription state from the dates:** trial → active → grace (full use) → read-only (view, export, pay) → archived. Suspended is set by hand.
- **Read-only:** changes are refused with `402 SUBSCRIPTION_READ_ONLY`. Sign-in, reports, exports and payment still work.
- **Packages:** a module left out of a package is switched off like on the Modules screen. `feature:voice` and `feature:online_shop` guard the voice and shop routes. Users, branches and products are counted against the package's limits.
- **Single mode:** the same limits can be set in `.env` (`PATOPRO_FEATURES`, `PATOPRO_LIMIT_*`). By default everything is allowed with no limits, so existing installs do not change.
- **New endpoint:** `GET /api/v1/business` – the business, its state and its package (for banners and menus).

Not yet: sign-up page, payments, SMS warnings, website path routing (`/<business>/…`), mobile phone sign-in directory. These come in the next steps.

## Commands (platform mode)

```bash
php artisan platform:install                      # central tables + example packages (edit their prices!)
php artisan platform:business:create duka-la-juma "Duka la Juma" --plan=biashara --owner="Juma Mussa" --phone=0712345678
php artisan platform:list                         # businesses, package, state, end date
php artisan platform:migrate                      # central + every business (deploy.sh runs this)
php artisan platform:run schedule:run             # scheduled tasks for every business (the one cron line)
php artisan platform:run cache:clear --business=duka-la-juma
PATOPRO_BUSINESS=duka-la-juma php artisan tinker  # any command for one business
```

Run the same create command again if a setup failed (for example the database did not exist yet).

## Setting up patopro.shezoni.com (when we get there)

1. **In DirectAdmin → MySQL**, create:
   - the central database and its user;
   - one "business" user that will be granted every business database.
   Each new business database is created in DirectAdmin and granted to that user. The next step automates this through the DirectAdmin API.
2. **Copy the code:** `git clone … ~/platform`. In `backend/.env`, set `PATOPRO_MODE=platform`, the `PLATFORM_*` lines (see `.env.example`), and `DB_DATABASE` = the central database.
3. **Install:** `php artisan platform:install`.
4. **Cron:** one line for the whole platform:
   `* * * * * cd /home/shezonic/platform/backend && /home/shezonic/bin/php artisan platform:run schedule:run >> storage/logs/cron.log 2>&1`

## Tests

```bash
php artisan test --filter=Platform
```
`SingleModeTest` proves single installs are unchanged. `PlatformModeTest` runs four businesses on separate SQLite files and checks:
- each business reads only its own database;
- packages switch modules on and off and enforce limits;
- grace and read-only work;
- a suspended business is closed.
