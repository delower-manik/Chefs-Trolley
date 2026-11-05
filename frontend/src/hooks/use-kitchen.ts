"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type { KitchenTicket } from "@/types/kitchen";

/**
 * Polls every few seconds rather than pushing over a socket. This is an
 * interim solution -- the architecture calls for a real WebSocket/SignalR
 * layer (Phase 11) that will replace this polling with true push updates.
 */
function stationQuery(stationId?: string) {
  return stationId ? `?station_id=${encodeURIComponent(stationId)}` : "";
}

/** stationId: a station uuid, "none" (unrouted dishes) or undefined (whole order). */
export function useKitchenTickets(stationId?: string) {
  const { data, error, isLoading, mutate } = useSWR<{ data: KitchenTicket[] }>(
    `/kitchen/tickets${stationQuery(stationId)}`,
    (p: string) => apiRequest<{ data: KitchenTicket[] }>(p),
    { refreshInterval: 5_000 }
  );

  return { tickets: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function bumpTicket(orderId: string, stationId?: string) {
  return apiRequest<{ data: KitchenTicket }>(`/kitchen/tickets/${orderId}/bump${stationQuery(stationId)}`, { method: "POST" });
}

export async function reopenTicket(orderId: string, stationId?: string) {
  return apiRequest<{ data: KitchenTicket }>(`/kitchen/tickets/${orderId}/reopen${stationQuery(stationId)}`, { method: "POST" });
}
