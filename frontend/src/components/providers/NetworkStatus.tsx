"use client";

import { useEffect, useRef, useState } from "react";
import { Hourglass, WifiOff } from "lucide-react";
import { toast } from "sonner";
import { useT } from "@/lib/i18n";

type State = "online" | "slow" | "offline";

/**
 * Tells "no internet on this device" apart from "our server is slow":
 *  - offline: the browser says so, or two checks in a row cannot reach this website at all;
 *  - slow: the website answers, but the system behind it takes long (or is busy).
 * A slow server never shows "No internet", so people with good internet are not told
 * to check their network, and nothing stops them from working.
 */
export function NetworkStatus() {
  const tr = useT();
  const [state, setState] = useState<State>("online");
  const last = useRef<State>("online");
  const failures = useRef(0);

  useEffect(() => {
    let cancelled = false;

    const check = async () => {
      if (!navigator.onLine) return update("offline");
      const ctrl = new AbortController();
      const timer = setTimeout(() => ctrl.abort(), 20000);
      const started = Date.now();
      try {
        const res = await fetch("/api/backend/health", { cache: "no-store", signal: ctrl.signal });
        failures.current = 0;
        // The website answered: the internet works. A late or failed answer from the
        // system behind it means "slow / busy", not "offline".
        update(!res.ok || Date.now() - started > 8000 ? "slow" : "online");
      } catch (e) {
        if ((e as Error).name === "AbortError") {
          failures.current = 0;
          return update("slow"); // waited 20 s: the server is very slow, the network is fine
        }
        // Could not reach this website at all: only after a second miss (one dropped request is normal on mobile data).
        failures.current += 1;
        update(failures.current >= 2 ? "offline" : last.current);
      } finally {
        clearTimeout(timer);
      }
    };

    const update = (next: State) => {
      if (cancelled) return;
      setState(next);
      if (next === "offline" && last.current !== "offline") {
        toast.error(tr("No internet connection. You can keep viewing, but changes will not be saved until you are back online."), { id: "offline", duration: 6000 });
      }
      if (next === "online" && last.current === "offline") {
        toast.success(tr("You are back online."), { id: "online" });
      }
      last.current = next;
    };

    const onOffline = () => update("offline");
    const onOnline = () => {
      failures.current = 0;
      check();
    };
    window.addEventListener("offline", onOffline);
    window.addEventListener("online", onOnline);
    const interval = setInterval(check, 30000);
    check();

    return () => {
      cancelled = true;
      window.removeEventListener("offline", onOffline);
      window.removeEventListener("online", onOnline);
      clearInterval(interval);
    };
  }, [tr]);

  if (state === "online") return null;

  if (state === "slow") {
    return (
      <div role="status" className="no-print fixed inset-x-0 top-0 z-[100] flex items-center justify-center gap-2 bg-saffron-300 px-4 py-1.5 text-xs font-medium text-night-900 shadow">
        <Hourglass className="size-3.5 shrink-0" aria-hidden />
        <span>{tr("The system is answering slowly right now. Your internet is fine — please wait a moment after each action.")}</span>
      </div>
    );
  }

  return (
    <div role="alert" className="no-print fixed inset-x-0 top-0 z-[100] flex items-center justify-center gap-2 bg-ember-600 px-4 py-2 text-sm font-medium text-white shadow-lg">
      <WifiOff className="size-4 shrink-0" aria-hidden />
      <span>{tr("You are offline. Check your internet connection — new sales and changes cannot be saved right now.")}</span>
    </div>
  );
}
