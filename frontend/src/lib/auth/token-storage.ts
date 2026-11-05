import type { AuthTokens } from "@/types/auth";

// Access tokens are kept in memory + sessionStorage (short-lived, tab-scoped).
// The refresh token is expected to also be set as an HttpOnly cookie by the
// API on login so it is never readable from JS in production; sessionStorage
// here is a development fallback only.
const STORAGE_KEY = "ct_auth_tokens";

export function getStoredTokens(): AuthTokens | null {
  if (typeof window === "undefined") return null;
  const raw = window.sessionStorage.getItem(STORAGE_KEY);
  if (!raw) return null;
  try {
    return JSON.parse(raw) as AuthTokens;
  } catch {
    return null;
  }
}

export function setStoredTokens(tokens: AuthTokens): void {
  if (typeof window === "undefined") return;
  window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(tokens));
}

export function clearStoredTokens(): void {
  if (typeof window === "undefined") return;
  window.sessionStorage.removeItem(STORAGE_KEY);
}
