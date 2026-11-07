# PatoPro mobile app

The SHEZONI PatoPro app for Android and iPhone, built with React Native and Expo (SDK 58, Expo Router). It talks to the same Laravel backend as the web app (`../backend`, routes in `routes/api/mobile.php`).

## What it does

- **Works without internet.** Products, stock, customers, orders, recent sales and notifications are kept in a local SQLite database. Selling, adding customers, taking debt payments and confirming or completing orders are saved on the phone straight away.
- **Two-way sync**, automatic and manual:
  - The phone sends its changes first, then downloads what changed on the server, page by page.
  - Every change has an ID, so the server applies it exactly once, even if the connection drops halfway.
  - Changes the server refuses (no stock, no permission, changed by someone else) are kept under *Needs attention* to fix or discard. They are never retried blindly.
  - Changes that fail on the server are retried with backoff: 15 s, 30 s, 1 min … up to 30 min.
  - Sync runs automatically when:
    - the internet comes back (also checked every 15 s against the server's `/api/v1/health` while offline);
    - the app opens;
    - 1.5 s after any change;
    - every 1, 2, 5 or 15 minutes while the app is open;
    - a push notification arrives;
    - every ~15 minutes in the background.
  - **Sync now** is on the Sync centre screen, along with the waiting, sent and history lists. Pull down on Home or Orders to sync too.
  - *Only on Wi-Fi* applies to automatic syncs only; *Sync now* always works.
- **Notifications with voice, sound and vibration**, chosen separately for each kind of notification: online orders, stock alerts, money and sales, team and reminders, and other.
  - **Voice** reads the notification aloud in Kiswahili or English, using the phone's own text-to-speech, so it also works offline.
  - **Sound** and **vibration** can be on or off independently.
  - **Quiet hours** silence alerts at night. The notification still appears, just without voice, sound or vibration.
  - Settings go to the server, which sends each phone its push on the matching Android channel.
  - Every notification appears in the phone's notification bar. Tapping it opens the exact order, product, sale or notification it is about, after the app lock if one is set.
  - Order notifications have a **Confirm order** button.
- **Till**:
  - most-sold products first;
  - barcode scanning, including several codes in a row;
  - pack units (hold a product);
  - retail and wholesale prices;
  - cash, mobile money, bank, card and credit payments;
  - receipts by WhatsApp, PDF or print.
- **Customers**: add and edit them, see what they owe, take debt payments and send a WhatsApp reminder.
- **App lock** with fingerprint, face or a PIN. Kiswahili and English. Light and dark mode.

## Run it

```bash
npm install
EXPO_PUBLIC_API_URL=https://api.yourdomain.co.tz npx expo start
```

Expo Go cannot run this app because of background tasks and push notifications. Use a development build instead (`npx expo run:android`, or `npx eas-cli@latest build --profile development`).

Checks before a release:

```bash
npm run typecheck && npm run lint && npm test && npm run i18n:check
```

## Build and publish (EAS)

1. Create an Expo account. Then run `npx eas-cli@latest login` and `npx eas-cli@latest init`. This writes the project ID into `app.json → extra.eas.projectId`, and push notifications need it.
2. Put your backend address in `eas.json` (`EXPO_PUBLIC_API_URL`, for each profile).
3. Android push: create a Firebase project and add an Android app with package `com.shezoni.patopro`. Upload its FCM V1 service-account key with `npx eas-cli@latest credentials` (Android → Push Notifications).
4. iPhone push: EAS creates the APNs key during `eas build` if you have an Apple Developer account.
5. Build:
   - `npm run build:android` gives an installable APK (profile `preview`);
   - `npx eas-cli@latest build --profile production` gives the Play Store / App Store builds;
   - `npx eas-cli@latest submit` uploads them.

## Backend settings

In the backend `.env`:

```
MOBILE_PUSH_ENABLED=true
EXPO_ACCESS_TOKEN=      # optional; required only if you turn on "enhanced push security" in Expo
```

Then run `php artisan migrate` to create the mobile tables (phones and applied changes).

## Notes

- **Android** fixes a channel's sound and vibration when the channel is created. The app therefore creates four channels (sound + vibration, sound only, vibration only, silent), and the server picks the channel that matches the phone's settings for each push.
- **iPhone** does not let apps turn vibration on or off separately: it follows the phone's own sound and vibration settings. Voice and sound work as chosen.
- **Kiswahili voice** needs a Kiswahili text-to-speech voice on the phone. Google Text-to-speech has one. Without it, the app speaks English and says so in the settings.
