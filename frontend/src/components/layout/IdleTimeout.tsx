"use client";

import { useEffect, useRef, useState } from "react";
import { useAuth } from "@/lib/auth";
import { Button, Modal } from "@/components/ui";
import { api } from "@/lib/api";
import { useT } from "@/lib/i18n";

/** Shared by every open tab, so working in one tab keeps the others signed in too. */
const ACTIVITY_KEY = "patopro.last-activity";

function sharedActivity(): number {
  try {
    return Number(window.localStorage.getItem(ACTIVITY_KEY)) || 0;
  } catch {
    return 0;
  }
}

/**
 * Mirrors the server-side inactivity timeout set by the admin: warns one minute
 * before the session ends and logs the user out when time is up.
 * While the person is working (typing, clicking, scrolling) the server is told every
 * few minutes, so a long form or a page read without saving never ends the session.
 */
export function IdleTimeout() {
  const t = useT();
  const { user, logout } = useAuth();
  const minutes = user?.session_timeout_minutes ?? 30;
  const [warning, setWarning] = useState(false);
  const [secondsLeft, setSecondsLeft] = useState(60);
  const lastActivity = useRef(0);
  const lastShared = useRef(0);
  const lastPing = useRef(0);

  useEffect(() => {
    if (!user) return;
    lastActivity.current = Date.now();
    lastPing.current = Date.now();
    const mark = () => {
      const now = Date.now();
      lastActivity.current = now;
      if (now - lastShared.current > 5000) {
        lastShared.current = now;
        try {
          window.localStorage.setItem(ACTIVITY_KEY, String(now));
        } catch {
          /* private mode: this tab only */
        }
      }
    };
    const events = ["mousemove", "keydown", "pointerdown", "scroll", "touchstart", "wheel", "input"];
    events.forEach((e) => window.addEventListener(e, mark, { passive: true, capture: true }));

    const limitMs = minutes * 60_000;
    // Tell the server well before its clock runs out (at most every 5 minutes).
    const pingEvery = Math.max(30_000, Math.min(5 * 60_000, limitMs / 3));

    const timer = setInterval(() => {
      const now = Date.now();
      const latest = Math.max(lastActivity.current, sharedActivity());
      const idleMs = now - latest;
      const left = Math.ceil((limitMs - idleMs) / 1000);

      if (idleMs < 60_000 && now - lastPing.current >= pingEvery) {
        lastPing.current = now;
        api.get("auth/me").catch(() => undefined); // keeps the server-side activity clock fresh
      }

      if (left <= 0) {
        clearInterval(timer);
        logout(t("You were logged out after {minutes} minutes of inactivity.", { minutes }));
      } else if (left <= 60) {
        setWarning(true);
        setSecondsLeft(left);
      } else {
        setWarning(false);
      }
    }, 1000);

    return () => {
      events.forEach((e) => window.removeEventListener(e, mark, { capture: true }));
      clearInterval(timer);
    };
  }, [user, minutes, logout, t]);

  const stay = async () => {
    lastActivity.current = Date.now();
    lastPing.current = Date.now();
    try {
      window.localStorage.setItem(ACTIVITY_KEY, String(Date.now()));
    } catch {
      /* this tab only */
    }
    setWarning(false);
    try {
      await api.get("auth/me"); // refreshes the server-side activity clock
    } catch {
      /* handled globally */
    }
  };

  return (
    <Modal
      open={warning}
      onClose={stay}
      title={t("Are you still there?")}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={() => logout()}>{t("Log out now")}</Button>
          <Button onClick={stay} data-autofocus>{t("Stay logged in")}</Button>
        </>
      }
    >
      <p className="text-sm text-ink-700">
        {t("For security you will be logged out in")} <strong className="num">{secondsLeft}</strong> {t("seconds because there has been no activity.")}
      </p>
    </Modal>
  );
}
