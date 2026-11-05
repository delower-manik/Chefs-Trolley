"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type { CreateOrderPayload, Order, OrderFilters, PaginatedResponse } from "@/types/order";

function buildQuery(filters: OrderFilters): string {
  const params = new URLSearchParams();
  if (filters.status) params.set("status", filters.status);
  if (filters.type) params.set("type", filters.type);
  if (filters.branchId) params.set("branch_id", filters.branchId);
  if (filters.search) params.set("search", filters.search);
  params.set("page", String(filters.page ?? 1));
  params.set("per_page", String(filters.perPage ?? 20));
  return params.toString();
}

export function useOrders(filters: OrderFilters) {
  const query = buildQuery(filters);
  const { data, error, isLoading, mutate } = useSWR<PaginatedResponse<Order>>(
    `/orders?${query}`,
    (path: string) => apiRequest<PaginatedResponse<Order>>(path),
    { keepPreviousData: true }
  );

  return {
    orders: data?.data ?? [],
    meta: data?.meta,
    isLoading,
    error,
    refresh: mutate,
  };
}

export function useOrder(id: string) {
  const { data, error, isLoading, mutate } = useSWR<{ data: Order }>(
    id ? `/orders/${id}` : null,
    (path: string) => apiRequest<{ data: Order }>(path)
  );

  return { order: data?.data, isLoading, error, refresh: mutate };
}

export async function createOrder(payload: CreateOrderPayload, idempotencyKey?: string) {
  return apiRequest<{ data: Order }>("/orders", { method: "POST", body: payload, idempotencyKey });
}
