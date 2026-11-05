"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type { CmsPage, MediaItem, SaveCmsPagePayload, SaveSiteSettingsPayload, SiteSettings } from "@/types/cms";

type Paged<T> = { data: T[]; meta: { currentPage: number; perPage: number; total: number; lastPage: number } };

// ---------- Pages ----------

export function useCmsPages() {
  const { data, error, isLoading, mutate } = useSWR<{ data: CmsPage[] }>("/cms/pages", (p: string) =>
    apiRequest<{ data: CmsPage[] }>(p)
  );
  return { pages: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function createCmsPage(payload: SaveCmsPagePayload) {
  return apiRequest<{ data: CmsPage }>("/cms/pages", { method: "POST", body: payload });
}

export async function updateCmsPage(id: string, payload: Partial<SaveCmsPagePayload>) {
  return apiRequest<{ data: CmsPage }>(`/cms/pages/${id}`, { method: "PUT", body: payload });
}

export async function deleteCmsPage(id: string) {
  return apiRequest<void>(`/cms/pages/${id}`, { method: "DELETE" });
}

export async function publishCmsPage(id: string) {
  return apiRequest<{ data: CmsPage }>(`/cms/pages/${id}/publish`, { method: "POST" });
}

export async function unpublishCmsPage(id: string) {
  return apiRequest<{ data: CmsPage }>(`/cms/pages/${id}/unpublish`, { method: "POST" });
}

// ---------- Media ----------

export function useMedia() {
  const { data, error, isLoading, mutate } = useSWR<Paged<MediaItem>>("/media?per_page=40", (p: string) =>
    apiRequest<Paged<MediaItem>>(p)
  );
  return { media: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function uploadMedia(file: File, altText?: string) {
  const form = new FormData();
  form.append("file", file);
  if (altText) form.append("alt_text", altText);
  return apiRequest<{ data: MediaItem }>("/media", { method: "POST", body: form });
}

export async function deleteMedia(id: string) {
  return apiRequest<void>(`/media/${id}`, { method: "DELETE" });
}

// ---------- Site Settings ----------

export function useSiteSettings() {
  const { data, error, isLoading, mutate } = useSWR<{ data: SiteSettings }>("/site-settings", (p: string) =>
    apiRequest<{ data: SiteSettings }>(p)
  );
  return { settings: data?.data, isLoading, error, refresh: mutate };
}

export async function updateSiteSettings(payload: SaveSiteSettingsPayload) {
  return apiRequest<{ data: SiteSettings }>("/site-settings", { method: "PUT", body: payload });
}
