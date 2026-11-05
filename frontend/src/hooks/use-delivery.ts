"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type {
  AssignDeliveryPayload,
  DeliverOrderPayload,
  DeliveryAssignment,
  DeliveryZone,
  Rider,
  SaveDeliveryZonePayload,
  SaveRiderPayload,
} from "@/types/delivery";

export function useDeliveries(status?: string) {
  const params = new URLSearchParams();
  if (status) params.set("status", status);

  const { data, error, isLoading, mutate } = useSWR<{ data: DeliveryAssignment[] }>(
    `/deliveries?${params.toString()}`,
    (p: string) => apiRequest<{ data: DeliveryAssignment[] }>(p),
    { refreshInterval: 8000 }
  );
  return { deliveries: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function assignDelivery(orderId: string, payload: AssignDeliveryPayload) {
  return apiRequest<{ data: DeliveryAssignment }>(`/deliveries/${orderId}/assign`, { method: "POST", body: payload });
}

export async function pickupDelivery(orderId: string) {
  return apiRequest<{ data: DeliveryAssignment }>(`/deliveries/${orderId}/pickup`, { method: "POST" });
}

export async function deliverOrder(orderId: string, payload: DeliverOrderPayload) {
  return apiRequest<{ data: DeliveryAssignment }>(`/deliveries/${orderId}/deliver`, { method: "POST", body: payload });
}

export async function failDelivery(orderId: string, reason: string) {
  return apiRequest<{ data: DeliveryAssignment }>(`/deliveries/${orderId}/fail`, { method: "POST", body: { reason } });
}

export function useRiders() {
  const { data, error, isLoading, mutate } = useSWR<{ data: Rider[] }>("/riders", (p: string) =>
    apiRequest<{ data: Rider[] }>(p)
  );
  return { riders: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function createRider(payload: SaveRiderPayload) {
  return apiRequest<{ data: Rider }>("/riders", { method: "POST", body: payload });
}

export async function updateRider(id: string, payload: Partial<SaveRiderPayload>) {
  return apiRequest<{ data: Rider }>(`/riders/${id}`, { method: "PUT", body: payload });
}

export async function deleteRider(id: string) {
  return apiRequest<void>(`/riders/${id}`, { method: "DELETE" });
}

export function useDeliveryZones() {
  const { data, error, isLoading, mutate } = useSWR<{ data: DeliveryZone[] }>("/delivery-zones", (p: string) =>
    apiRequest<{ data: DeliveryZone[] }>(p)
  );
  return { zones: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function createDeliveryZone(payload: SaveDeliveryZonePayload) {
  return apiRequest<{ data: DeliveryZone }>("/delivery-zones", { method: "POST", body: payload });
}

export async function updateDeliveryZone(id: string, payload: Partial<SaveDeliveryZonePayload>) {
  return apiRequest<{ data: DeliveryZone }>(`/delivery-zones/${id}`, { method: "PUT", body: payload });
}

export async function deleteDeliveryZone(id: string) {
  return apiRequest<void>(`/delivery-zones/${id}`, { method: "DELETE" });
}
