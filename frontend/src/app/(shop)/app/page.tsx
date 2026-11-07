"use client";

import { useEffect, useState } from "react";
import { CheckCircle2, Download, ShieldCheck, Smartphone, WifiOff, BellRing, RefreshCw } from "lucide-react";
import { date } from "@/lib/format";
import { useT } from "@/lib/i18n";
import { BRAND } from "@/lib/brand";
import { EmptyState, Skeleton } from "@/components/ui";
import { PatoProMark } from "@/components/brand/PatoProLogo";
import { QrCode } from "@/features/catalog/components/QrCode";
import { fileSize, useAppRelease, useDevice } from "@/features/mobile-app/AppDownload";

/**
 * Public "Download the app" page (/app, also /pakua).
 * The Android app is not in an app store: the APK is downloaded from here and installed by hand.
 */
export default function AppDownloadPage() {
  const t = useT();
  const { release, isLoading } = useAppRelease();
  const device = useDevice();
  const [pageUrl, setPageUrl] = useState("");
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the address is only known in the browser
    setPageUrl(`${window.location.origin}/app`);
  }, []);

  const steps = [
    t("Tap “Download the app” below. The file (PatoPro-x.x.x.apk) is saved to your phone."),
    t("Open the downloaded file from the notification bar or the Downloads / Files app."),
    t("If the phone asks, allow installing apps from this source (Chrome or Files), then go back."),
    t("If Google Play Protect warns that the app is unknown, tap “More details” → “Install anyway”. This is normal for apps outside the Play Store."),
    t("Open PatoPro and sign in with the same username and password you use on the website."),
  ];

  return (
    <div className="mx-auto max-w-5xl sm:py-4">
      <div className="grid gap-8 lg:grid-cols-[1.15fr_1fr] lg:items-start">
        <section>
          <div className="flex items-center gap-3">
            <PatoProMark className="size-12" />
            <div>
              <h1 className="text-2xl font-bold tracking-tight text-ink-900 sm:text-3xl">{t("{app} mobile app", { app: BRAND.product })}</h1>
              <p className="text-sm text-ink-500">{t("For Android phones and tablets")}</p>
            </div>
          </div>

          <p className="mt-5 text-ink-700">
            {t("Sell, receive online orders and check stock from your phone — even when there is no internet. Everything syncs with the system as soon as the connection returns.")}
          </p>

          <ul className="mt-5 grid gap-2 text-sm text-ink-700 sm:grid-cols-2">
            <li className="flex items-center gap-2"><WifiOff className="size-4 text-brand-600" />{t("Works without internet")}</li>
            <li className="flex items-center gap-2"><RefreshCw className="size-4 text-brand-600" />{t("Automatic two-way sync")}</li>
            <li className="flex items-center gap-2"><BellRing className="size-4 text-brand-600" />{t("Order alerts with voice and vibration")}</li>
            <li className="flex items-center gap-2"><ShieldCheck className="size-4 text-brand-600" />{t("Same account and permissions as the website")}</li>
          </ul>

          <div className="mt-7 rounded-2xl bg-surface p-5 ring-1 ring-ink-200/70">
            {isLoading ? (
              <div className="space-y-3"><Skeleton className="h-12 w-full" /><Skeleton className="h-4 w-1/2" /></div>
            ) : !release ? (
              <EmptyState icon={<Smartphone className="size-6" />} title={t("The app is not available for download yet")} message={t("Ask the administrator to upload the app. Meanwhile you can use the website on your phone.")} />
            ) : (
              <>
                <a
                  href={release.download_url}
                  className="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-5 py-4 text-lg font-semibold text-white shadow-sm transition-colors hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                  download
                  rel="nofollow"
                >
                  <Download className="size-6" />
                  {t("Download the app")}
                </a>
                <p className="mt-3 text-center text-sm text-ink-600">
                  {[t("Version {version}", { version: release.version }), fileSize(release.file_size), release.released_at ? date(release.released_at) : ""].filter(Boolean).join(" · ")}
                </p>
                {device === "ios" && (
                  <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    {t("This app is for Android. On an iPhone, open this website in Safari and choose Share → “Add to Home Screen” to use PatoPro like an app.")}
                  </p>
                )}
                {release.notes && (
                  <div className="mt-4 border-t border-ink-100 pt-4">
                    <h2 className="text-sm font-semibold text-ink-900">{t("What's new")}</h2>
                    <p className="mt-1 whitespace-pre-line text-sm text-ink-700">{release.notes}</p>
                  </div>
                )}
                {release.sha256 && (
                  <p className="mt-4 break-all font-mono text-[10px] leading-tight text-ink-400" title={t("File fingerprint (SHA-256) to confirm the download is genuine")}>
                    SHA-256: {release.sha256}
                  </p>
                )}
              </>
            )}
          </div>
        </section>

        <aside className="space-y-6">
          <div className="rounded-2xl bg-surface p-5 ring-1 ring-ink-200/70">
            <h2 className="font-semibold text-ink-900">{t("How to install")}</h2>
            <ol className="mt-3 space-y-3">
              {steps.map((s, i) => (
                <li key={i} className="flex gap-3 text-sm text-ink-700">
                  <span className="grid size-6 shrink-0 place-items-center rounded-full bg-brand-100 text-xs font-bold text-brand-800">{i + 1}</span>
                  <span>{s}</span>
                </li>
              ))}
            </ol>
            <p className="mt-4 flex items-start gap-2 text-xs text-ink-500">
              <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-emerald-600" />
              {t("When a new version is released the app tells you, and you install it the same way. Your data stays on the phone and on the server.")}
            </p>
          </div>

          {device !== "android" && pageUrl && release && (
            <div className="flex items-center gap-4 rounded-2xl bg-surface p-5 ring-1 ring-ink-200/70">
              <QrCode value={pageUrl} size={112} />
              <div className="text-sm text-ink-700">
                <p className="font-semibold text-ink-900">{t("On a computer?")}</p>
                <p className="mt-1">{t("Scan this code with your Android phone's camera to open this page there and download the app.")}</p>
                <p className="mt-2 font-mono text-xs text-ink-500">{pageUrl.replace(/^https?:\/\//, "")}</p>
              </div>
            </div>
          )}
        </aside>
      </div>
    </div>
  );
}
