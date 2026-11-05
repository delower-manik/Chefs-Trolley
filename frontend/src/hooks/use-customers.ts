"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type {
  Customer,
  CustomerOrderSummary,
  LoyaltyAdjustmentPayload,
  LoyaltyLedgerEntry,
  SaveCustomerPayload,
} from "@/types/customer";

type Paged<T> = { data: T[]; meta: { currentPage: number; perPage: number; total: number; lastPage: number } };

export function useCustomers(search?: string) {
  const params = new URLSearchParams();
  if (search) params.set("search", search);

  const { data, error, isLoading, mutate } = useSWR<Paged<Customer>>(
    `/customers?${params.toString()}`,
    (p: string) => apiRequest<Paged<Customer>>(p),
    { keepPreviousData: true }
  );

  return { customers: data?.data ?? [], meta: data?.meta, isLoading, error, refresh: mutate };
}

export async function createCustomer(payload: SaveCustomerPayload) {
  return apiRequest<{ data: Customer }>("/customers", { method: "POST", body: payload });
}

export async function updateCustomer(id: string, payload: Partial<SaveCustomerPayload>) {
  return apiRequest<{ data: Customer }>(`/customers/${id}`, { method: "PUT", body: payload });
}

export async function deleteCustomer(id: string) {
  return apiRequest<void>(`/customers/${id}`, { method: "DELETE" });
}

export function useCustomerOrders(customerId: string | null) {
  const { data, error, isLoading } = useSWR<{ data: CustomerOrderSummary[] }>(
    customerId ? `/customers/${customerId}/orders` : null,
    (p: string) => apiRequest<{ data: CustomerOrderSummary[] }>(p)
  );
  return { orders: data?.data ?? [], isLoading, error };
}

export function useLoyaltyLedger(customerId: string | null) {
  const { data, error, isLoading, mutate } = useSWR<{ data: LoyaltyLedgerEntry[] }>(
    customerId ? `/customers/${customerId}/loyalty/ledger` : null,
    (p: string) => apiRequest<{ data: LoyaltyLedgerEntry[] }>(p)
  );
  return { entries: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function adjustLoyalty(customerId: string, payload: LoyaltyAdjustmentPayload) {
  return apiRequest<{ data: Customer }>(`/customers/${customerId}/loyalty/adjust`, { method: "POST", body: payload });
}
