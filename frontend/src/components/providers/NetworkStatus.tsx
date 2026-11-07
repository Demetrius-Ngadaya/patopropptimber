"use client";

import { useEffect, useRef, useState } from "react";
import { WifiOff } from "lucide-react";
import { toast } from "sonner";
import { useT } from "@/lib/i18n";

/**
 * Detects loss of internet two ways: the browser's online/offline events and a
 * periodic lightweight ping (a connected Wi-Fi without internet still reports "online").
 */
export function NetworkStatus() {
  const tr = useT();
  const [offline, setOffline] = useState(false);
  const wasOffline = useRef(false);

  useEffect(() => {
    let cancelled = false;

    const check = async () => {
      if (!navigator.onLine) return update(true);
      try {
        const ctrl = new AbortController();
        const t = setTimeout(() => ctrl.abort(), 6000);
        await fetch("/api/backend/health", { cache: "no-store", signal: ctrl.signal });
        clearTimeout(t);
        // Any HTTP answer means this device can reach us; only a network failure means offline.
        update(false);
      } catch {
        update(true);
      }
    };

    const update = (isOffline: boolean) => {
      if (cancelled) return;
      setOffline(isOffline);
      if (isOffline && !wasOffline.current) {
        toast.error(tr("No internet connection. You can keep viewing, but changes will not be saved until you are back online."), { id: "offline", duration: 6000 });
      }
      if (!isOffline && wasOffline.current) {
        toast.success(tr("You are back online."), { id: "online" });
      }
      wasOffline.current = isOffline;
    };

    const onOffline = () => update(true);
    const onOnline = () => check();
    window.addEventListener("offline", onOffline);
    window.addEventListener("online", onOnline);
    const interval = setInterval(check, 20000);
    check();

    return () => {
      cancelled = true;
      window.removeEventListener("offline", onOffline);
      window.removeEventListener("online", onOnline);
      clearInterval(interval);
    };
  }, []);

  if (!offline) return null;

  return (
    <div role="alert" className="no-print fixed inset-x-0 top-0 z-[100] flex items-center justify-center gap-2 bg-ember-600 px-4 py-2 text-sm font-medium text-white shadow-lg">
      <WifiOff className="size-4 shrink-0" aria-hidden />
      <span>{tr("You are offline. Check your internet connection — new sales and changes cannot be saved right now.")}</span>
    </div>
  );
}
