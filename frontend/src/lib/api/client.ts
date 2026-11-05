import { getStoredTokens, setStoredTokens, clearStoredTokens } from "@/lib/auth/token-storage";

const API_BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8000/api/v1";

export class ApiError extends Error {
  status: number;
  code: string;
  fieldErrors?: Record<string, string[]>;

  constructor(status: number, code: string, message: string, fieldErrors?: Record<string, string[]>) {
    super(message);
    this.status = status;
    this.code = code;
    this.fieldErrors = fieldErrors;
  }
}

type RequestOptions = {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
  branchId?: string; // enforces branch scope per architecture §11
  signal?: AbortSignal;
  skipAuth?: boolean;
  /** "blob" is for file downloads (e.g. CSV export); errors are still parsed as JSON. */
  parseAs?: "json" | "blob";
  /** Use a caller-chosen key so a replayed request is recognised by the server as the same request (offline queue). */
  idempotencyKey?: string;
};

/** fetch() rejects with a TypeError when there is no connection at all (as opposed to an HTTP error status). */
export const isNetworkError = (e: unknown): boolean => e instanceof TypeError;

let refreshPromise: Promise<string | null> | null = null;

async function refreshAccessToken(): Promise<string | null> {
  const tokens = getStoredTokens();
  if (!tokens?.refreshToken) return null;

  if (!refreshPromise) {
    refreshPromise = fetch(`${API_BASE_URL}/auth/refresh`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ refreshToken: tokens.refreshToken }),
    })
      .then(async (res) => {
        if (!res.ok) {
          clearStoredTokens();
          return null;
        }
        const json = await res.json();
        setStoredTokens(json.data);
        return json.data.accessToken as string;
      })
      .finally(() => {
        refreshPromise = null;
      });
  }
  return refreshPromise;
}

/**
 * Every write mutates through this single client so error handling,
 * auth headers, branch scoping and idempotency keys stay consistent
 * (Skill: API Engineer).
 */
export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = "GET", body, branchId, signal, skipAuth = false, parseAs = "json", idempotencyKey } = options;

  const doFetch = async (accessToken: string | null): Promise<Response> => {
    const isFormData = body instanceof FormData;
    const headers: Record<string, string> = {};
    // FormData sets its own multipart Content-Type (with boundary) --
    // setting it manually here would break the upload.
    if (!isFormData) headers["Content-Type"] = "application/json";
    if (accessToken && !skipAuth) headers.Authorization = `Bearer ${accessToken}`;
    if (branchId) headers["X-Branch-Id"] = branchId;
    if (method !== "GET") headers["Idempotency-Key"] = idempotencyKey ?? crypto.randomUUID();

    return fetch(`${API_BASE_URL}${path}`, {
      method,
      headers,
      body: isFormData ? (body as FormData) : body ? JSON.stringify(body) : undefined,
      signal,
    });
  };

  const tokens = getStoredTokens();
  let response = await doFetch(tokens?.accessToken ?? null);

  if (response.status === 401 && !skipAuth) {
    const newToken = await refreshAccessToken();
    if (newToken) {
      response = await doFetch(newToken);
    }
  }

  if (!response.ok) {
    let payload: { code?: string; message?: string; errors?: Record<string, string[]> } = {};
    try {
      payload = await response.json();
    } catch {
      // non-JSON error body (e.g. gateway timeout) — fall through to generic message
    }
    throw new ApiError(
      response.status,
      payload.code ?? "unknown_error",
      payload.message ?? "Something went wrong. Please try again.",
      payload.errors
    );
  }

  if (response.status === 204) return undefined as T;
  if (parseAs === "blob") return (await response.blob()) as T;
  return (await response.json()) as T;
}
