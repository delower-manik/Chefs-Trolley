"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type { GatewayOption, GatewaySession } from "@/types/gateway";

export function useGatewayOptions() {
  const { data } = useSWR<{ data: GatewayOption[] }>("/gateway-payments/options", (p: string) => apiRequest<{ data: GatewayOption[] }>(p));
  return data?.data ?? [];
}

/** Polls an open session every few seconds until the server says it is settled or closed. */
export function useGatewaySession(id: string | null) {
  const { data, mutate } = useSWR<{ data: GatewaySession }>(id ? `/gateway-payments/${id}` : null, (p: string) => apiRequest<{ data: GatewaySession }>(p), {
    refreshInterval: (d) => (d && ["created", "pending"].includes(d.data.status) ? 4000 : 0),
  });
  return { session: data?.data ?? null, refresh: mutate };
}

export async function startGatewayPayment(orderId: string, gateway: string, amount?: number) {
  return (await apiRequest<{ data: GatewaySession }>(`/orders/${orderId}/gateway-payments`, { method: "POST", body: { gateway, amount } })).data;
}

export async function checkGatewayPayment(id: string) {
  return (await apiRequest<{ data: GatewaySession }>(`/gateway-payments/${id}/check`, { method: "POST" })).data;
}

export async function confirmGatewayPayment(id: string, transactionId: string) {
  return (await apiRequest<{ data: GatewaySession }>(`/gateway-payments/${id}/confirm`, { method: "POST", body: { transaction_id: transactionId } })).data;
}

export async function cancelGatewayPayment(id: string) {
  return (await apiRequest<{ data: GatewaySession }>(`/gateway-payments/${id}/cancel`, { method: "POST" })).data;
}
