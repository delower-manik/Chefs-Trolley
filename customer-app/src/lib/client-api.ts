import { API } from "./config";

const KEY = "ct_web_token";
export const getToken = (): string | null => {
  try { return window.localStorage.getItem(KEY); } catch { return null; }
};
export const setToken = (t: string | null) => {
  try { t ? window.localStorage.setItem(KEY, t) : window.localStorage.removeItem(KEY); } catch { /* storage blocked */ }
};

export class ApiError extends Error {
  constructor(public status: number, public code: string, message: string, public fieldErrors?: Record<string, string[]>) { super(message); }
}

export async function api<T>(path: string, opts: { method?: string; body?: unknown; idempotencyKey?: string } = {}): Promise<T> {
  const token = getToken();
  let res: Response;
  try {
    res = await fetch(`${API}${path}`, {
      method: opts.method ?? (opts.body ? "POST" : "GET"),
      headers: {
        Accept: "application/json", "Content-Type": "application/json",
        ...(token ? { Authorization: `Bearer ${token}` } : {}), ...(opts.idempotencyKey ? { "Idempotency-Key": opts.idempotencyKey } : {}),
      },
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
  } catch {
    throw new ApiError(0, "network", "Can't reach the restaurant right now. Check your connection and try again.");
  }
  const json = await res.json().catch(() => ({}));
  if (!res.ok) {
    if (res.status === 401) setToken(null);
    throw new ApiError(res.status, json.code ?? "error", json.message ?? "Something went wrong.", json.errors);
  }
  return (json as { data: T }).data;
}
