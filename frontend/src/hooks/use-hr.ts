"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type {
  AttendanceHistoryEntry,
  AttendanceRosterEntry,
  Employee,
  MarkAttendancePayload,
  SaveEmployeePayload,
} from "@/types/hr";

export function useEmployees(search?: string, activeOnly?: boolean) {
  const params = new URLSearchParams();
  if (search) params.set("search", search);
  if (activeOnly) params.set("active_only", "1");

  const { data, error, isLoading, mutate } = useSWR<{ data: Employee[] }>(
    `/employees?${params.toString()}`,
    (p: string) => apiRequest<{ data: Employee[] }>(p)
  );
  return { employees: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function createEmployee(payload: SaveEmployeePayload) {
  return apiRequest<{ data: Employee }>("/employees", { method: "POST", body: payload });
}

export async function updateEmployee(id: string, payload: Partial<SaveEmployeePayload>) {
  return apiRequest<{ data: Employee }>(`/employees/${id}`, { method: "PUT", body: payload });
}

export async function deleteEmployee(id: string) {
  return apiRequest<void>(`/employees/${id}`, { method: "DELETE" });
}

export function useAttendanceRoster(date: string) {
  const { data, error, isLoading, mutate } = useSWR<{ data: AttendanceRosterEntry[] }>(
    `/attendance?date=${date}`,
    (p: string) => apiRequest<{ data: AttendanceRosterEntry[] }>(p)
  );
  return { roster: data?.data ?? [], isLoading, error, refresh: mutate };
}

export function useEmployeeAttendanceHistory(employeeId: string | null) {
  const { data, error, isLoading } = useSWR<{ data: AttendanceHistoryEntry[] }>(
    employeeId ? `/attendance/employee/${employeeId}` : null,
    (p: string) => apiRequest<{ data: AttendanceHistoryEntry[] }>(p)
  );
  return { history: data?.data ?? [], isLoading, error };
}

export async function markAttendance(payload: MarkAttendancePayload) {
  return apiRequest<{ data: AttendanceHistoryEntry }>("/attendance", { method: "POST", body: payload });
}
