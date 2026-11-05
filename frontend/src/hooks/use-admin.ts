"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import { toQuery } from "@/lib/admin-utils";
import type {
  ActivityLogFilters,
  AdminRole,
  AdminUser,
  AssignableOptions,
  AuditLogEntry,
  Branch,
  CreateUserPayload,
  Paged,
  PermissionItem,
  SaveBranchPayload,
  SaveRolePayload,
  UpdateUserPayload,
  UserFilters,
} from "@/types/admin";

// ---------- Users ----------

export function useUsers(filters: UserFilters) {
  const query = toQuery({ q: filters.q, status: filters.status, page: filters.page });
  const { data, error, isLoading, mutate } = useSWR<Paged<AdminUser>>(`/users?${query}`, (p: string) =>
    apiRequest<Paged<AdminUser>>(p)
  );
  return { users: data?.data ?? [], meta: data?.meta, isLoading, error, refresh: mutate };
}

export function useAssignableOptions() {
  const { data, error, isLoading } = useSWR<{ data: AssignableOptions }>("/users/options", (p: string) =>
    apiRequest<{ data: AssignableOptions }>(p)
  );
  return { options: data?.data, isLoading, error };
}

export const createUser = (payload: CreateUserPayload) =>
  apiRequest<{ data: AdminUser }>("/users", { method: "POST", body: payload });

export const updateUser = (id: string, payload: UpdateUserPayload) =>
  apiRequest<{ data: AdminUser }>(`/users/${id}`, { method: "PUT", body: payload });

export const resetUserPassword = (id: string, password: string) =>
  apiRequest<{ data: AdminUser }>(`/users/${id}/password`, { method: "POST", body: { password } });

export const deleteUser = (id: string) => apiRequest<void>(`/users/${id}`, { method: "DELETE" });

// ---------- Roles & permissions ----------

export function useRoles() {
  const { data, error, isLoading, mutate } = useSWR<{ data: AdminRole[] }>("/roles", (p: string) =>
    apiRequest<{ data: AdminRole[] }>(p)
  );
  return { roles: data?.data ?? [], isLoading, error, refresh: mutate };
}

export function usePermissions() {
  const { data, error, isLoading } = useSWR<{ data: PermissionItem[] }>("/permissions", (p: string) =>
    apiRequest<{ data: PermissionItem[] }>(p)
  );
  return { permissions: data?.data ?? [], isLoading, error };
}

export const createRole = (payload: SaveRolePayload) =>
  apiRequest<{ data: AdminRole }>("/roles", { method: "POST", body: payload });

export const updateRole = (id: string, payload: Partial<SaveRolePayload>) =>
  apiRequest<{ data: AdminRole }>(`/roles/${id}`, { method: "PUT", body: payload });

export const deleteRole = (id: string) => apiRequest<void>(`/roles/${id}`, { method: "DELETE" });

// ---------- Branches ----------

export function useBranches() {
  const { data, error, isLoading, mutate } = useSWR<{ data: Branch[] }>("/branches", (p: string) =>
    apiRequest<{ data: Branch[] }>(p)
  );
  return { branches: data?.data ?? [], isLoading, error, refresh: mutate };
}

export const createBranch = (payload: SaveBranchPayload) =>
  apiRequest<{ data: Branch }>("/branches", { method: "POST", body: payload });

export const updateBranch = (id: string, payload: SaveBranchPayload) =>
  apiRequest<{ data: Branch }>(`/branches/${id}`, { method: "PUT", body: payload });

// ---------- Activity log ----------

export function useActivityLogs(filters: ActivityLogFilters) {
  const query = toQuery({ ...filters });
  const { data, error, isLoading } = useSWR<Paged<AuditLogEntry>>(`/activity-logs?${query}`, (p: string) =>
    apiRequest<Paged<AuditLogEntry>>(p)
  );
  return { entries: data?.data ?? [], meta: data?.meta, isLoading, error };
}

export function useActivityActions() {
  const { data } = useSWR<{ data: string[] }>("/activity-logs/actions", (p: string) =>
    apiRequest<{ data: string[] }>(p)
  );
  return { actions: data?.data ?? [] };
}
