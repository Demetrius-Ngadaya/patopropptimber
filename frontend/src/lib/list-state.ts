"use client";

import { useEffect, useRef, useState, type Dispatch, type SetStateAction } from "react";
import { usePathname } from "next/navigation";

/**
 * Coming back to a list after opening one of its records puts the person where
 * they were: same page of the table, same filters and search, same scroll position.
 *
 * "Coming back" means the browser Back button, or a link from a page inside the
 * list (e.g. /admin/sales/42 → /admin/sales). Opening the list from the menu
 * starts it fresh, as before. Everything is kept per browser tab (sessionStorage).
 */

let committedPath: string | null = null; // the page on screen before this render
let poppedAt = 0; // when the browser's Back / Forward button was last used
let restoringUntil = 0; // while scrolling back, positions are not saved

if (typeof window !== "undefined") {
  window.addEventListener("popstate", () => {
    poppedAt = Date.now();
  });
}

/** True while rendering a list that the person is returning to. */
function returning(pathname: string): boolean {
  if (Date.now() - poppedAt < 1500) return true;
  return Boolean(committedPath && committedPath !== pathname && committedPath.startsWith(`${pathname}/`));
}

const read = <T,>(key: string): T | undefined => {
  try {
    const raw = window.sessionStorage.getItem(key);
    return raw === null ? undefined : (JSON.parse(raw) as T);
  } catch {
    return undefined;
  }
};
const write = (key: string, value: unknown) => {
  try {
    window.sessionStorage.setItem(key, JSON.stringify(value));
  } catch {
    /* storage full or blocked: the list simply starts fresh next time */
  }
};

/**
 * useState for a list's page number and filters, remembered for the way back.
 * Drop-in: `const [page, setPage] = useListState("page", 1);`
 */
export function useListState<T>(name: string, initial: T | (() => T)): [T, Dispatch<SetStateAction<T>>] {
  const pathname = usePathname();
  const key = `list:${pathname}:${name}`;
  const [value, setValue] = useState<T>(() => {
    const fresh = typeof initial === "function" ? (initial as () => T)() : initial;
    if (typeof window === "undefined" || !returning(pathname)) return fresh;
    const saved = read<T>(key);
    // A saved object only fills the fields the page still knows about.
    if (saved !== undefined && fresh && typeof fresh === "object" && !Array.isArray(fresh) && saved && typeof saved === "object") {
      return { ...fresh, ...saved } as T;
    }
    return saved === undefined || typeof saved !== typeof fresh ? fresh : saved;
  });
  useEffect(() => {
    // Saved from the first render on, so a fresh visit replaces what an older one left.
    write(key, value);
  }, [key, value]);
  return [value, setValue];
}

/**
 * Remembers how far down each page was scrolled and scrolls back there when the
 * person returns to it (as defined above). Mounted once for the whole app.
 */
export function ScrollMemory() {
  const pathname = usePathname();
  const current = useRef(pathname);

  // Save the scroll position of the page on screen. The position is also taken at the
  // moment of a click / key press, because opening a record scrolls to the top
  // before this component hears about the new page.
  useEffect(() => {
    let hold = 0;
    const save = () => write(`scroll:${current.current}`, Math.round(window.scrollY));
    const onPress = () => {
      save();
      hold = Date.now();
    };
    const onScroll = () => {
      if (Date.now() - hold < 800 || Date.now() < restoringUntil) return; // the jump to the top of the next page, or our own restore
      save();
    };
    window.addEventListener("pointerdown", onPress, { capture: true, passive: true });
    window.addEventListener("keydown", onPress, { capture: true, passive: true });
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => {
      window.removeEventListener("pointerdown", onPress, { capture: true });
      window.removeEventListener("keydown", onPress, { capture: true });
      window.removeEventListener("scroll", onScroll);
    };
  }, []);

  useEffect(() => {
    const back = returning(pathname);
    current.current = pathname;
    committedPath = pathname;
    if (!back) return;

    const target = read<number>(`scroll:${pathname}`) ?? 0;
    if (target <= 0) return;
    // The table's rows arrive a moment later, and the router may move the page once more
    // after it shows: keep putting it back for a short while, and stop as soon as the
    // person scrolls by themselves.
    let stopped = false;
    const stop = () => {
      stopped = true;
      restoringUntil = 0;
    };
    const stopEvents = ["wheel", "touchmove", "keydown", "pointerdown"] as const;
    stopEvents.forEach((e) => window.addEventListener(e, stop, { passive: true, once: true }));
    const started = Date.now();
    restoringUntil = started + 2500;
    const timer = window.setInterval(() => {
      const reachable = document.documentElement.scrollHeight - window.innerHeight;
      const wanted = Math.min(target, Math.max(0, reachable));
      if (!stopped && Math.abs(window.scrollY - wanted) > 2) window.scrollTo({ top: wanted, behavior: "instant" as ScrollBehavior });
      const elapsed = Date.now() - started;
      if (stopped || (reachable >= target && elapsed > 1500) || elapsed > 2500) {
        window.clearInterval(timer);
        if (!stopped) write(`scroll:${pathname}`, Math.round(window.scrollY));
      }
    }, 100);
    return () => {
      window.clearInterval(timer);
      restoringUntil = 0;
      stopEvents.forEach((e) => window.removeEventListener(e, stop));
    };
  }, [pathname]);

  return null;
}
