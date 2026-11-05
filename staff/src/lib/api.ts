import { API } from "./config";
import type { Tokens } from "./types";

const KEY = "ct_staff_tokens";
// localStorage, not sessionStorage: a rider's phone closes the app all day and must not be signed out each time.
// The app renders no HTML from the server, and the server expires/revokes sessions regardless.
export const getTokens = (): Tokens | null => {
  try { return JSON.parse(window.localStorage.getItem(KEY) ?? "null"); } catch { return null; }
};
export const setTokens = (t: Tokens | null) => {
  try { t ? window.localStorage.setItem(KEY, JSON.stringify(t)) : window.localStorage.removeItem(KEY); } catch { /* storage blocked */ }
};

export class ApiError extends Error {
  constructor(public status: number, public code: string, message: string, public extra?: Record<string, unknown>) { super(message); }
}

let refreshing: Promise<boolean> | null = null;
async function refresh(): Promise<boolean> {
  const t = getTokens();
  if (!t?.refreshToken) return false;
  refreshing ??= fetch(`${API}/auth/refresh`, { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify({ refreshToken: t.refreshToken }) })
    .then(async (r) => { if (!r.ok) { setTokens(null); return false; } setTokens((await r.json()).data); return true; })
    .catch(() => false) // a network blip is not a sign-out
    .finally(() => { refreshing = null; });
  return refreshing;
}

type Opts = { method?: string; body?: unknown; auth?: boolean; headers?: Record<string, string> };
export async function api<T>(path: string, opts: Opts = {}): Promise<T> {
  const go = () => fetch(`${API}${path}`, {
    method: opts.method ?? (opts.body !== undefined ? "POST" : "GET"),
    headers: { Accept: "application/json", "Content-Type": "application/json", ...(opts.headers ?? {}), ...(opts.auth === false ? {} : { Authorization: `Bearer ${getTokens()?.accessToken ?? ""}` }) },
    body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
  });
  let res: Response;
  try { res = await go(); } catch { throw new ApiError(0, "network", "No connection."); }
  if (res.status === 401 && opts.auth !== false && (await refresh())) {
    try { res = await go(); } catch { throw new ApiError(0, "network", "No connection."); }
  }
  if (res.status === 204) return undefined as T;
  const json = await res.json().catch(() => ({}));
  if (!res.ok) {
    if (res.status === 401 && opts.auth !== false) setTokens(null);
    throw new ApiError(res.status, json.code ?? "error", json.message ?? "Something went wrong.", json.errors);
  }
  return (json as { data: T }).data;
}
