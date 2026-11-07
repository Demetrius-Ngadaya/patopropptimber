"use client";

import { useEffect, useRef } from "react";
import { usePathname } from "next/navigation";
import { api } from "@/lib/api";

const HEARTBEAT_MS = 60_000;

/**
 * Website visitor counter: tells the server about each page opened, and once
 * a minute that the page is still open (so "online now" stays right). Silent:
 * a failed beacon never bothers the visitor.
 */
export function VisitTracker() {
  const pathname = usePathname();
  const first = useRef(true);

  useEffect(() => {
    if (!pathname) return;
    const referrer = first.current ? document.referrer || undefined : undefined;
    first.current = false;
    void api.post("public/visits", { path: pathname, referrer, type: "page_view" }).catch(() => undefined);
  }, [pathname]);

  useEffect(() => {
    const beat = () => {
      if (document.visibilityState === "visible") void api.post("public/visits", { path: window.location.pathname, type: "heartbeat" }).catch(() => undefined);
    };
    const id = window.setInterval(beat, HEARTBEAT_MS);
    document.addEventListener("visibilitychange", beat);
    return () => { window.clearInterval(id); document.removeEventListener("visibilitychange", beat); };
  }, []);

  return null;
}
