"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type {
  AccountingSummary,
  CreatePayrollPeriodPayload,
  Expense,
  ExpenseCategory,
  PayrollEntry,
  PayrollPeriod,
  SaveExpensePayload,
} from "@/types/finance";

type Paged<T> = { data: T[]; meta: { currentPage: number; perPage: number; total: number; lastPage: number } };

// ---------- Payroll ----------

export function usePayrollPeriods() {
  const { data, error, isLoading, mutate } = useSWR<{ data: PayrollPeriod[] }>("/payroll/periods", (p: string) =>
    apiRequest<{ data: PayrollPeriod[] }>(p)
  );
  return { periods: data?.data ?? [], isLoading, error, refresh: mutate };
}

export function usePayrollPeriod(id: string | null) {
  const { data, error, isLoading, mutate } = useSWR<{ data: PayrollPeriod }>(
    id ? `/payroll/periods/${id}` : null,
    (p: string) => apiRequest<{ data: PayrollPeriod }>(p)
  );
  return { period: data?.data, isLoading, error, refresh: mutate };
}

export async function createPayrollPeriod(payload: CreatePayrollPeriodPayload) {
  return apiRequest<{ data: PayrollPeriod }>("/payroll/periods", { method: "POST", body: payload });
}

export async function generatePayroll(periodId: string) {
  return apiRequest<{ data: PayrollPeriod }>(`/payroll/periods/${periodId}/generate`, { method: "POST" });
}

export async function updatePayrollEntryBonus(entryId: string, bonuses: number) {
  return apiRequest<{ data: PayrollEntry }>(`/payroll/entries/${entryId}`, { method: "PUT", body: { bonuses } });
}

export async function finalizePayroll(periodId: string) {
  return apiRequest<{ data: PayrollPeriod }>(`/payroll/periods/${periodId}/finalize`, { method: "POST" });
}

export async function payPayroll(periodId: string) {
  return apiRequest<{ data: PayrollPeriod }>(`/payroll/periods/${periodId}/pay`, { method: "POST" });
}

// ---------- Expenses ----------

export function useExpenseCategories() {
  const { data, error, isLoading, mutate } = useSWR<{ data: ExpenseCategory[] }>("/expense-categories", (p: string) =>
    apiRequest<{ data: ExpenseCategory[] }>(p)
  );
  return { categories: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function createExpenseCategory(name: string) {
  return apiRequest<{ data: ExpenseCategory }>("/expense-categories", { method: "POST", body: { name } });
}

export async function deleteExpenseCategory(id: string) {
  return apiRequest<void>(`/expense-categories/${id}`, { method: "DELETE" });
}

export function useExpenses(from?: string, to?: string) {
  const params = new URLSearchParams();
  if (from) params.set("from", from);
  if (to) params.set("to", to);

  const { data, error, isLoading, mutate } = useSWR<Paged<Expense>>(
    `/expenses?${params.toString()}`,
    (p: string) => apiRequest<Paged<Expense>>(p)
  );
  return { expenses: data?.data ?? [], meta: data?.meta, isLoading, error, refresh: mutate };
}

export async function createExpense(payload: SaveExpensePayload) {
  return apiRequest<{ data: Expense }>("/expenses", { method: "POST", body: payload });
}

export async function deleteExpense(id: string) {
  return apiRequest<void>(`/expenses/${id}`, { method: "DELETE" });
}

// ---------- Accounting ----------

export function useAccountingSummary(from?: string, to?: string) {
  const params = new URLSearchParams();
  if (from) params.set("from", from);
  if (to) params.set("to", to);

  const { data, error, isLoading } = useSWR<{ data: AccountingSummary }>(
    `/accounting/summary?${params.toString()}`,
    (p: string) => apiRequest<{ data: AccountingSummary }>(p)
  );
  return { summary: data?.data, isLoading, error };
}
