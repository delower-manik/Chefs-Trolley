"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type {
  GenerateVouchersPayload,
  PaymentGateway,
  SavePaymentGatewayPayload,
  SaveWebhookPayload,
  SaveWifiZonePayload,
  Webhook,
  WifiVoucher,
  WifiZone,
} from "@/types/integrations";

// ---------- Payment Gateways ----------

export function usePaymentGateways() {
  const { data, error, isLoading, mutate } = useSWR<{ data: PaymentGateway[] }>("/payment-gateways", (p: string) =>
    apiRequest<{ data: PaymentGateway[] }>(p)
  );
  return { gateways: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function updatePaymentGateway(id: string, payload: SavePaymentGatewayPayload) {
  return apiRequest<{ data: PaymentGateway }>(`/payment-gateways/${id}`, { method: "PUT", body: payload });
}

// ---------- Webhooks ----------

export function useWebhooks() {
  const { data, error, isLoading, mutate } = useSWR<{ data: Webhook[] }>("/webhooks", (p: string) =>
    apiRequest<{ data: Webhook[] }>(p)
  );
  return { webhooks: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function createWebhook(payload: SaveWebhookPayload) {
  return apiRequest<{ data: Webhook }>("/webhooks", { method: "POST", body: payload });
}

export async function updateWebhook(id: string, payload: Partial<SaveWebhookPayload>) {
  return apiRequest<{ data: Webhook }>(`/webhooks/${id}`, { method: "PUT", body: payload });
}

export async function deleteWebhook(id: string) {
  return apiRequest<void>(`/webhooks/${id}`, { method: "DELETE" });
}

// ---------- Wi-Fi Zones & Vouchers ----------

export function useWifiZones() {
  const { data, error, isLoading, mutate } = useSWR<{ data: WifiZone[] }>("/wifi-zones", (p: string) =>
    apiRequest<{ data: WifiZone[] }>(p)
  );
  return { zones: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function createWifiZone(payload: SaveWifiZonePayload) {
  return apiRequest<{ data: WifiZone }>("/wifi-zones", { method: "POST", body: payload });
}

export async function deleteWifiZone(id: string) {
  return apiRequest<void>(`/wifi-zones/${id}`, { method: "DELETE" });
}

export function useWifiVouchers(zoneId: string | null) {
  const { data, error, isLoading, mutate } = useSWR<{ data: WifiVoucher[] }>(
    zoneId ? `/wifi-zones/${zoneId}/vouchers` : null,
    (p: string) => apiRequest<{ data: WifiVoucher[] }>(p)
  );
  return { vouchers: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function generateWifiVouchers(zoneId: string, payload: GenerateVouchersPayload) {
  return apiRequest<{ data: WifiVoucher[] }>(`/wifi-zones/${zoneId}/vouchers/generate`, { method: "POST", body: payload });
}

export async function redeemWifiVoucher(voucherId: string) {
  return apiRequest<{ data: WifiVoucher }>(`/wifi/vouchers/${voucherId}/redeem`, { method: "POST" });
}
