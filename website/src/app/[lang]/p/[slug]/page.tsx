import { notFound } from "next/navigation";
import type { Metadata } from "next";
import { getPage } from "@/lib/server-api";
import { isLocale } from "@/lib/locale";
import { alternates } from "@/lib/seo";
import Sections from "@/components/Sections";

type P = { params: Promise<{ lang: string; slug: string }> };
export async function generateMetadata(props: P): Promise<Metadata> {
  const params = await props.params;
  const p = await getPage(params.slug);
  return p ? { title: p.metaTitle ?? p.title, description: p.metaDescription ?? undefined, openGraph: p.ogImageUrl ? { images: [p.ogImageUrl] } : undefined, alternates: isLocale(params.lang) ? alternates(params.lang, `/p/${encodeURIComponent(params.slug)}`) : undefined } : { title: "Not found", robots: { index: false } };
}

export default async function CmsPage(props: P) {
  const params = await props.params;
  const p = await getPage(params.slug);
  if (!p) notFound();
  return (<article><h1 className="mb-6 text-3xl font-bold">{p.title}</h1><Sections sections={p.sections} /></article>);
}
