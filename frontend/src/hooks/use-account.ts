"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type { AccountInfo, BackupMeta, BackupRun, SessionInfo } from "@/types/account";

const get = <T,>(p: string) => apiRequest<T>(p);

export function useAccount() {
  const { data, mutate } = useSWR<{ data: AccountInfo }>("/account", get);
  return { account: data?.data ?? null, refresh: mutate };
}

export function useSessions() {
  const { data, mutate } = useSWR<{ data: SessionInfo[] }>("/account/sessions", get);
  return { sessions: data?.data ?? [], refresh: mutate };
}

export const updateProfile = (name: string) => apiRequest("/account/profile", { method: "PUT", body: { name } });

export const changePassword = (current_password: string, password: string, password_confirmation: string) =>
  apiRequest("/account/password", { method: "PUT", body: { current_password, password, password_confirmation } });

export const startMfa = () => apiRequest<{ data: { secret: string; uri: string } }>("/account/mfa/setup", { method: "POST" }).then((r) => r.data);
export const confirmMfa = (code: string) => apiRequest<{ data: { recoveryCodes: string[] } }>("/account/mfa/confirm", { method: "POST", body: { code } }).then((r) => r.data.recoveryCodes);
export const regenerateRecoveryCodes = (password: string, code: string) =>
  apiRequest<{ data: { recoveryCodes: string[] } }>("/account/mfa/recovery-codes", { method: "POST", body: { password, code } }).then((r) => r.data.recoveryCodes);
export const disableMfa = (password: string, code: string) => apiRequest("/account/mfa/disable", { method: "POST", body: { password, code } });

export const revokeSession = (id: string) => apiRequest(`/account/sessions/${encodeURIComponent(id)}`, { method: "DELETE" });
export const revokeOtherSessions = () => apiRequest("/account/sessions", { method: "DELETE" });

export function useBackups() {
  const { data, mutate } = useSWR<{ data: BackupRun[]; meta: BackupMeta }>("/backups", get, { refreshInterval: 15000 });
  return { runs: data?.data ?? [], meta: data?.meta ?? null, refresh: mutate };
}

export const runBackupNow = () => apiRequest<{ data: BackupRun }>("/backups", { method: "POST" }).then((r) => r.data);
export const verifyBackup = (id: string) => apiRequest<{ data: { ok: boolean; message: string } }>(`/backups/${id}/verify`, { method: "POST" }).then((r) => r.data);
