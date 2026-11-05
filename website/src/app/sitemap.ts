import type { MetadataRoute } from "next";
import { SITE_URL } from "@/lib/config";
import { LOCALES, localePath } from "@/lib/locale";
import { getBranches, getMenu, getSitemap } from "@/lib/server-api";

/** One entry per page per language, each listing its translations (hreflang). Dish pages come from the default branch's menu. */
// Built per request: dish pages come from the live menu, which isn't reachable at build time.
export const dynamic = "force-dynamic";

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const [data, branches] = await Promise.all([getSitemap(), getBranches()]);
  const menu = branches[0] ? await getMenu(branches[0].id) : null;
  const entry = (path: string, lastModified?: string | Date): MetadataRoute.Sitemap =>
    LOCALES.map((l) => ({
      url: `${SITE_URL}${localePath(l, path)}`, lastModified,
      alternates: { languages: Object.fromEntries(LOCALES.map((x) => [x, `${SITE_URL}${localePath(x, path)}`])) },
    }));
  return [
    ...entry("/"), ...entry("/menu"),
    ...(data?.pages ?? []).filter((p) => p.slug !== "home").flatMap((p) => entry(`/p/${encodeURIComponent(p.slug)}`, p.updatedAt)),
    ...(menu?.categories ?? []).flatMap((c) => c.items.flatMap((i) => entry(`/menu/${encodeURIComponent(i.slug)}`))),
  ];
}
