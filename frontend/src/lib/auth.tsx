"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { api, authRequest, getSelectedBranch, setSelectedBranch, type ApiResponse } from "./api";
import type { SessionUser } from "./types";
import { useT } from "@/lib/i18n";

/** Pseudo-permission for Software Engineer-only screens. */
export const DEVELOPER = "@developer";
/** Any staff user (personal pages such as notes and the inbox). */
export const ANY_STAFF = "@staff";

type AuthContextValue = {
  user: SessionUser | null;
  loading: boolean;
  /** true if the user has ANY of the given permissions (as resolved by the server). */
  can: (permission: string | string[]) => boolean;
  branchId: string | null;
  setBranch: (id: string) => void;
  refresh: () => Promise<SessionUser | null>;
  setUser: (user: SessionUser | null) => void;
  logout: (message?: string) => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const t = useT();
  const [user, setUser] = useState<SessionUser | null>(null);
  const [loading, setLoading] = useState(true);
  const [branchId, setBranchId] = useState<string | null>(null);
  const queryClient = useQueryClient();
  const router = useRouter();

  const refresh = useCallback(async () => {
    try {
      const res = await api.get<ApiResponse<SessionUser>>("auth/me");
      setUser(res.data);
      return res.data;
    } catch {
      setUser(null);
      return null;
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    refresh();
  }, [refresh]);

  // Pick a valid branch: the saved choice if still allowed, else the user's own branch.
  useEffect(() => {
    if (!user || user.type !== "staff") return;
    const saved = getSelectedBranch();
    const allowed = new Set((user.branches ?? []).map((b) => String(b.id)));
    const valid = saved && (allowed.has(saved) || (saved === "all" && user.all_branches));
    const next = valid ? saved : user.branch ? String(user.branch.id) : user.branches?.[0] ? String(user.branches[0].id) : null;
    setSelectedBranch(next);
    setBranchId(next);
  }, [user]);

  const setBranch = useCallback(
    (id: string) => {
      setSelectedBranch(id);
      setBranchId(id);
      queryClient.invalidateQueries();
    },
    [queryClient],
  );

  const logout = useCallback(
    async (message?: string) => {
      const wasStaff = user?.type === "staff";
      try {
        await authRequest("logout");
      } catch {
        /* cookie is cleared server side anyway */
      }
      setUser(null);
      queryClient.clear();
      if (message) toast.info(message);
      router.replace(wasStaff ? "/login" : "/");
    },
    [queryClient, router, user?.type],
  );

  // The gateway reports 401s (expired / revoked sessions) through this event.
  useEffect(() => {
    const onEnded = (e: Event) => {
      const message = (e as CustomEvent<string>).detail;
      if (!user) return;
      setUser(null);
      queryClient.clear();
      toast.error(message || t("Your session has ended. Please log in again."));
      const path = window.location.pathname;
      router.replace(`/login?next=${encodeURIComponent(path)}`);
    };
    window.addEventListener("mz:session-ended", onEnded);
    return () => window.removeEventListener("mz:session-ended", onEnded);
  }, [queryClient, router, user]);

  const can = useCallback(
    (permission: string | string[]) => {
      if (!user || user.type !== "staff") return false;
      // The server already resolves everything: role, personal grants / denies, and
      // switched-off modules (which apply to super admins too). "@developer" marks
      // Software Engineer-only screens.
      const list = Array.isArray(permission) ? permission : [permission];
      return list.some((p) => (p === ANY_STAFF ? true : p === DEVELOPER ? Boolean(user.role?.is_developer) : user.permissions?.includes(p)));
    },
    [user],
  );

  const value = useMemo(
    () => ({ user, loading, can, branchId, setBranch, refresh, setUser, logout }),
    [user, loading, can, branchId, setBranch, refresh, logout],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used inside <AuthProvider>");
  return ctx;
}
