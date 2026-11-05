"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type {
  Campaign,
  CampaignAudience,
  GiftCard,
  GiftCardEntry,
  GiftCardList,
  GiftCardLookup,
  IssueGiftCardPayload,
  SaveCampaignPayload,
  Segment,
  SegmentField,
  SegmentPreview,
  SegmentRule,
} from "@/types/marketing";

const get = <T,>(p: string) => apiRequest<T>(p);

// ---- gift cards ----
export function useGiftCards(status?: string, search?: string, page = 1) {
  const qs = new URLSearchParams({ page: String(page) });
  if (status) qs.set("status", status);
  if (search) qs.set("search", search);
  const { data, error, isLoading, mutate } = useSWR<GiftCardList>(`/gift-cards?${qs}`, get);
  return { cards: data?.data ?? [], meta: data?.meta, isLoading, error, refresh: mutate };
}

export function useGiftCard(id: string | null) {
  const { data, mutate } = useSWR<{ data: GiftCard & { entries: GiftCardEntry[] } }>(id ? `/gift-cards/${id}` : null, get);
  return { card: data?.data, refresh: mutate };
}

export const issueGiftCard = (body: IssueGiftCardPayload) => apiRequest<{ data: GiftCard; code: string }>("/gift-cards", { method: "POST", body });
export const lookupGiftCard = async (code: string) => (await apiRequest<{ data: GiftCardLookup }>("/gift-cards/lookup", { method: "POST", body: { code } })).data;
export const adjustGiftCard = (id: string, amount: number, reason: string) =>
  apiRequest<{ data: GiftCard }>(`/gift-cards/${id}/adjust`, { method: "POST", body: { amount, reason } });
export const voidGiftCard = (id: string, reason: string) => apiRequest<{ data: GiftCard }>(`/gift-cards/${id}/void`, { method: "POST", body: { reason } });

// ---- segments ----
export function useSegments() {
  const { data, error, isLoading, mutate } = useSWR<{ data: Segment[]; fields: SegmentField[] }>("/customer-segments", get);
  return { segments: data?.data ?? [], fields: data?.fields ?? [], isLoading, error, refresh: mutate };
}

type SegmentBody = { name: string; description?: string; match: "all" | "any"; rules: SegmentRule[] };
export const createSegment = (body: SegmentBody) => apiRequest<{ data: Segment }>("/customer-segments", { method: "POST", body });
export const updateSegment = (id: string, body: SegmentBody) => apiRequest<{ data: Segment }>(`/customer-segments/${id}`, { method: "PUT", body });
export const deleteSegment = (id: string) => apiRequest<{ message: string }>(`/customer-segments/${id}`, { method: "DELETE" });
export const previewSegment = (match: "all" | "any", rules: SegmentRule[]) =>
  apiRequest<SegmentPreview>("/customer-segments/preview", { method: "POST", body: { match, rules } });

// ---- campaigns ----
export function useCampaigns() {
  const { data, error, isLoading, mutate } = useSWR<{ data: Campaign[] }>("/campaigns", get);
  return { campaigns: data?.data ?? [], isLoading, error, refresh: mutate };
}

export function useCampaign(id: string | null) {
  const { data, mutate } = useSWR<{ data: Campaign }>(id ? `/campaigns/${id}` : null, get);
  return { campaign: data?.data, refresh: mutate };
}

export const saveCampaign = (id: string | null, body: SaveCampaignPayload) =>
  id ? apiRequest<{ data: Campaign }>(`/campaigns/${id}`, { method: "PUT", body }) : apiRequest<{ data: Campaign }>("/campaigns", { method: "POST", body });
export const deleteCampaign = (id: string) => apiRequest<{ message: string }>(`/campaigns/${id}`, { method: "DELETE" });
export const cancelCampaign = (id: string) => apiRequest<{ data: Campaign }>(`/campaigns/${id}/cancel`, { method: "POST" });
export const campaignAudience = async (id: string) => (await apiRequest<{ data: CampaignAudience }>(`/campaigns/${id}/audience`)).data;
export const sendCampaign = (id: string, confirmRecipients: number) =>
  apiRequest<{ data: Campaign; queued: Record<string, number> }>(`/campaigns/${id}/send`, { method: "POST", body: { confirm_recipients: confirmRecipients } });
