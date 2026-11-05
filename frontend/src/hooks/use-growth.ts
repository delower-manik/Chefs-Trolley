"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type { AppNotification, Promotion, Review, SaveNotificationPayload, SavePromotionPayload, SaveReviewPayload } from "@/types/growth";

type Paged<T> = { data: T[]; meta: { currentPage: number; perPage: number; total: number; lastPage: number } };

// ---------- Promotions ----------

export function usePromotions() {
  const { data, error, isLoading, mutate } = useSWR<{ data: Promotion[] }>("/promotions", (p: string) =>
    apiRequest<{ data: Promotion[] }>(p)
  );
  return { promotions: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function createPromotion(payload: SavePromotionPayload) {
  return apiRequest<{ data: Promotion }>("/promotions", { method: "POST", body: payload });
}

export async function updatePromotion(id: string, payload: Partial<SavePromotionPayload>) {
  return apiRequest<{ data: Promotion }>(`/promotions/${id}`, { method: "PUT", body: payload });
}

export async function deletePromotion(id: string) {
  return apiRequest<void>(`/promotions/${id}`, { method: "DELETE" });
}

// ---------- Reviews ----------

export function useReviews(published?: boolean) {
  const params = new URLSearchParams();
  if (published !== undefined) params.set("published", published ? "1" : "0");

  const { data, error, isLoading, mutate } = useSWR<Paged<Review>>(
    `/reviews?${params.toString()}`,
    (p: string) => apiRequest<Paged<Review>>(p)
  );
  return { reviews: data?.data ?? [], meta: data?.meta, isLoading, error, refresh: mutate };
}

export async function createReview(payload: SaveReviewPayload) {
  return apiRequest<{ data: Review }>("/reviews", { method: "POST", body: payload });
}

export async function publishReview(id: string) {
  return apiRequest<{ data: Review }>(`/reviews/${id}/publish`, { method: "POST" });
}

export async function unpublishReview(id: string) {
  return apiRequest<{ data: Review }>(`/reviews/${id}/unpublish`, { method: "POST" });
}

export async function deleteReview(id: string) {
  return apiRequest<void>(`/reviews/${id}`, { method: "DELETE" });
}

// ---------- Notifications ----------
// Internal announcement records only -- see the "not built yet" note on
// the Notifications page. There is no SMS/push/email provider wired up.

export function useNotifications() {
  const { data, error, isLoading, mutate } = useSWR<{ data: AppNotification[] }>("/notifications", (p: string) =>
    apiRequest<{ data: AppNotification[] }>(p)
  );
  return { notifications: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function createNotification(payload: SaveNotificationPayload) {
  return apiRequest<{ data: AppNotification }>("/notifications", { method: "POST", body: payload });
}

export async function sendNotification(id: string) {
  return apiRequest<{ data: AppNotification }>(`/notifications/${id}/send`, { method: "POST" });
}

export async function deleteNotification(id: string) {
  return apiRequest<void>(`/notifications/${id}`, { method: "DELETE" });
}
