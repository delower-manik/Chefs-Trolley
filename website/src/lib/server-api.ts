import { API } from "./config";
import type { Branch, Menu, Page, Site } from "./types";

/** Server-side reads of the public API. Cached briefly so a traffic spike never turns into a database spike. */
async function get<T>(path: string, revalidate = 60): Promise<T | null> {
  try {
    const res = await fetch(`${API}${path}`, { next: { revalidate }, headers: { Accept: "application/json" } });
    if (!res.ok) return null;
    return ((await res.json()) as { data: T }).data;
  } catch {
    return null; // API down: pages render a friendly fallback instead of crashing
  }
}

export const getSite = () => get<Site>("/web/site", 60);
export const getBranches = async () => (await get<Branch[]>("/web/branches", 120)) ?? [];
export const getMenu = (branchId: string) => get<Menu>(`/web/branches/${encodeURIComponent(branchId)}/menu`, 30);
export const getPage = (slug: string) => get<Page>(`/web/pages/${encodeURIComponent(slug)}`, 60);
export const getSitemap = () => get<{ pages: { slug: string; updatedAt: string }[]; branches: { id: string; updatedAt: string }[] }>("/web/sitemap", 300);
export const getReviews = (branchId: string) =>
  get<{ average: number; count: number; reviews: { name: string | null; rating: number; comment: string | null; date: string }[] }>(`/web/branches/${encodeURIComponent(branchId)}/reviews`, 120);
