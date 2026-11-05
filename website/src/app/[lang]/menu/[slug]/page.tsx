import { notFound } from "next/navigation";
import type { Metadata } from "next";
import { getBranches, getMenu, getSite } from "@/lib/server-api";
import { SITE_URL } from "@/lib/config";
import { isLocale, localePath } from "@/lib/locale";
import { alternates, jsonLd } from "@/lib/seo";
import ItemOrder from "@/components/ItemOrder";
import L from "@/components/L";

type P = { params: Promise<{ lang: string; slug: string }>; searchParams: Promise<{ b?: string }> };

async function load(slug: string, b?: string) {
  const branches = await getBranches();
  const branch = branches.find((x) => x.id === b) ?? branches[0];
  const menu = branch ? await getMenu(branch.id) : null;
  if (!menu) return null;
  for (const c of menu.categories) {
    const item = c.items.find((i) => i.slug === slug);
    if (item) return { branch: menu.branch, category: c, item, isDefaultBranch: branch.id === branches[0].id };
  }
  return null;
}

export async function generateMetadata(props: P): Promise<Metadata> {
  const [params, searchParams] = await Promise.all([props.params, props.searchParams]);
  if (!isLocale(params.lang)) return {};
  const f = await load(params.slug, searchParams.b);
  if (!f) return { title: "Not found", robots: { index: false } };
  const path = `/menu/${encodeURIComponent(f.item.slug)}`;
  const alt = alternates(params.lang, path);
  // Items of the default branch are canonical without ?b=; another branch's page keeps it so the two never collide.
  const q = f.isDefaultBranch ? "" : `?b=${f.branch.id}`;
  return {
    title: f.item.name, description: f.item.description ?? undefined,
    alternates: { canonical: `${alt.canonical}${q}`, languages: Object.fromEntries(Object.entries(alt.languages ?? {}).map(([k, v]) => [k, `${v}${q}`])) },
    openGraph: { title: f.item.name, description: f.item.description ?? undefined, type: "website", images: f.item.imageUrl ? [f.item.imageUrl] : undefined },
  };
}

export default async function ItemPage(props: P) {
  const [params, searchParams] = await Promise.all([props.params, props.searchParams]);
  if (!isLocale(params.lang)) notFound();
  const [f, site] = await Promise.all([load(params.slug, searchParams.b), getSite()]);
  if (!f) notFound();
  const { item, category, branch } = f;
  const ld = jsonLd({
    "@context": "https://schema.org", "@type": "MenuItem", name: item.name, description: item.description ?? undefined, image: item.imageUrl ?? undefined,
    menuAddOn: item.modifierGroups.flatMap((g) => g.modifiers.map((m) => ({ "@type": "MenuItem", name: m.name }))),
    offers: { "@type": "Offer", price: item.price, priceCurrency: site?.currency.code ?? "BDT", availability: "https://schema.org/InStock", seller: { "@type": "Restaurant", name: site?.siteName, address: branch.address ?? undefined } },
    url: `${SITE_URL}${localePath(params.lang, `/menu/${encodeURIComponent(item.slug)}`)}`,
  });
  return (
    <article className="mx-auto max-w-2xl">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: ld }} />
      <nav aria-label="Breadcrumb" className="mb-3 text-sm text-soft"><L href={`/menu${f.isDefaultBranch ? "" : `?b=${branch.id}`}`} className="underline">Menu</L> / {category.name}</nav>
      {item.imageUrl && /* eslint-disable-next-line @next/next/no-img-element */ <img src={item.imageUrl} alt={item.name} className="mb-4 aspect-[4/3] w-full rounded-2xl object-cover" />}
      <h1 className="text-3xl font-bold">{item.name}</h1>
      <p className="mt-1 text-xl font-semibold text-brand">{site ? `${site.currency.symbol}${item.price}` : item.price}</p>
      {item.description && <p className="mt-3 whitespace-pre-line text-soft">{item.description}</p>}
      <div className="mt-6"><ItemOrder item={item} branchId={branch.id} /></div>
    </article>
  );
}
