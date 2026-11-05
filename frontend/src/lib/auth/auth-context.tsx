"use client";

import { createContext, useContext, useEffect, useState, useCallback } from "react";
import { useRouter } from "next/navigation";
import { apiRequest, ApiError } from "@/lib/api/client";
import { getStoredTokens, setStoredTokens, clearStoredTokens } from "@/lib/auth/token-storage";
import type { AuthUser, LoginPayload, AuthSession, MfaChallenge } from "@/types/auth";

type AuthContextValue = {
  user: AuthUser | null;
  status: "loading" | "authenticated" | "unauthenticated";
  /** Resolves with a challenge when a second step is needed; otherwise signs in and resolves with null. */
  login: (payload: LoginPayload) => Promise<MfaChallenge | null>;
  verifyMfa: (challenge: string, code: string) => Promise<void>;
  logout: () => Promise<void>;
  hasPermission: (permission: string) => boolean;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [status, setStatus] = useState<AuthContextValue["status"]>("loading");
  const router = useRouter();

  const loadSession = useCallback(async () => {
    const tokens = getStoredTokens();
    if (!tokens) {
      setStatus("unauthenticated");
      return;
    }
    try {
      const me = await apiRequest<{ data: AuthUser }>("/auth/me");
      setUser(me.data);
      setStatus("authenticated");
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        clearStoredTokens();
      }
      setStatus("unauthenticated");
    }
  }, []);

  useEffect(() => {
    loadSession();
  }, [loadSession]);

  const finishSession = useCallback(
    (session: AuthSession) => {
      setStoredTokens(session.tokens);
      setUser(session.user);
      setStatus("authenticated");
      // Lightweight, non-sensitive flag so the edge middleware can route
      // unauthenticated visitors to /login. Real authorization is always
      // enforced server-side by the API -- this cookie carries no secret.
      document.cookie = "ct_authed=1; path=/; samesite=lax";
      router.push("/dashboard");
    },
    [router]
  );

  const login = useCallback(
    async (payload: LoginPayload): Promise<MfaChallenge | null> => {
      const res = await apiRequest<{ data: AuthSession | MfaChallenge }>("/auth/login", {
        method: "POST",
        body: payload,
        skipAuth: true,
      });
      if ("mfaRequired" in res.data) return res.data;
      finishSession(res.data);
      return null;
    },
    [finishSession]
  );

  const verifyMfa = useCallback(
    async (challenge: string, code: string) => {
      const res = await apiRequest<{ data: AuthSession }>("/auth/mfa", { method: "POST", body: { challenge, code }, skipAuth: true });
      finishSession(res.data);
    },
    [finishSession]
  );

  const logout = useCallback(async () => {
    try {
      await apiRequest("/auth/logout", { method: "POST" });
    } finally {
      clearStoredTokens();
      document.cookie = "ct_authed=; path=/; max-age=0";
      setUser(null);
      setStatus("unauthenticated");
      router.push("/login");
    }
  }, [router]);

  const hasPermission = useCallback(
    (permission: string) => {
      if (!user) return false;
      // Super Admin role bypasses granular checks; still enforced server-side too.
      if (user.roles.some((r) => r.name === "Super Admin")) return true;
      return user.permissions.includes(permission);
    },
    [user]
  );

  return (
    <AuthContext.Provider value={{ user, status, login, verifyMfa, logout, hasPermission }}>{children}</AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within AuthProvider");
  return ctx;
}
