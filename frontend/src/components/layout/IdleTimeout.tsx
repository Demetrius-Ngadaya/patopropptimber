"use client";

import { useEffect, useRef, useState } from "react";
import { useAuth } from "@/lib/auth";
import { Button, Modal } from "@/components/ui";
import { api } from "@/lib/api";
import { useT } from "@/lib/i18n";

/**
 * Mirrors the server-side inactivity timeout set by the admin: warns one minute
 * before the session ends and logs the user out when time is up.
 */
export function IdleTimeout() {
  const t = useT();
  const { user, logout } = useAuth();
  const minutes = user?.session_timeout_minutes ?? 30;
  const [warning, setWarning] = useState(false);
  const [secondsLeft, setSecondsLeft] = useState(60);
  const lastActivity = useRef(0);

  useEffect(() => {
    if (!user) return;
    lastActivity.current = Date.now();
    const mark = () => {
      lastActivity.current = Date.now();
    };
    const events = ["mousemove", "keydown", "pointerdown", "scroll", "touchstart"];
    events.forEach((e) => window.addEventListener(e, mark, { passive: true }));

    const timer = setInterval(() => {
      const idleMs = Date.now() - lastActivity.current;
      const limitMs = minutes * 60_000;
      const left = Math.ceil((limitMs - idleMs) / 1000);
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
      events.forEach((e) => window.removeEventListener(e, mark));
      clearInterval(timer);
    };
  }, [user, minutes, logout]);

  const stay = async () => {
    lastActivity.current = Date.now();
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
