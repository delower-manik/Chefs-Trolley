"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type { DeliveryLogResponse, NotificationDelivery, NotifyChannel, SendResult, WebhookDeliveryRow } from "@/types/notify";
import type { AppNotification } from "@/types/growth";

export function useDeliveryLog(status?: string) {
  const qs = status ? `?status=${encodeURIComponent(status)}` : "";
  const { data, error, isLoading, mutate } = useSWR<DeliveryLogResponse>(`/notification-deliveries${qs}`, (p: string) =>
    apiRequest<DeliveryLogResponse>(p)
  );
  return { deliveries: data?.data ?? [], channels: data?.channels, isLoading, error, refresh: mutate };
}

export const sendAnnouncement = (id: string, channels: NotifyChannel[]) =>
  apiRequest<{ data: AppNotification } & SendResult>(`/notifications/${id}/send`, { method: "POST", body: { channels } });

export const sendTestMessage = (channel: NotifyChannel, recipient: string) =>
  apiRequest<{ data: NotificationDelivery }>("/notification-deliveries/test", { method: "POST", body: { channel, recipient } });

export const retryDelivery = (id: string) =>
  apiRequest<{ data: NotificationDelivery }>(`/notification-deliveries/${id}/retry`, { method: "POST" });

export function useWebhookDeliveries(webhookId: string | null) {
  const { data, error, isLoading, mutate } = useSWR<{ data: WebhookDeliveryRow[] }>(
    webhookId ? `/webhooks/${webhookId}/deliveries` : null,
    (p: string) => apiRequest<{ data: WebhookDeliveryRow[] }>(p)
  );
  return { deliveries: data?.data ?? [], isLoading, error, refresh: mutate };
}

export const redeliverWebhook = (deliveryId: string) =>
  apiRequest<{ data: { id: string; status: string } }>(`/webhook-deliveries/${deliveryId}/redeliver`, { method: "POST" });
