import type { Metadata } from "next";
import L from "@/components/L";
import { getBranches, getMenu } from "@/lib/server-api";
import MenuView from "@/components/MenuView";
import { isLocale } from "@/lib/locale";
import { alternates, jsonLd } from "@/lib/seo";
import { SITE_URL } from "@/lib/config";

export async function generateMetadata(props: { params: Promise<{ lang: string }> }): Promise<Metadata> {
  const params = await props.params;
  return { title: "Menu", alternates: isLocale(params.lang) ? alternates(params.lang, "/menu") : undefined };
}

export default async function MenuPage(props: { searchParams: Promise<{ b?: string }>; params: Promise<{ lang: string }> }) {
  const [searchParams, params] = await Promise.all([props.searchParams, props.params]);
  const branches = await getBranches();
  const branch = branches.find((b) => b.id === searchParams.b) ?? branches[0];
  const menu = branch ? await getMenu(branch.id) : null;
  if (!branch || !menu) return <p role="alert" className="card">Menu unavailable. Please try again shortly.</p>;
  // Whole-menu structured data (schema.org Menu > MenuSection > MenuItem with prices) for rich results.
  const ld = jsonLd({
    "@context": "https://schema.org", "@type": "Menu", name: `${branch.name} menu`, url: `${SITE_URL}/${params.lang}/menu`,
    hasMenuSection: menu.categories.map((c) => ({ "@type": "MenuSection", name: c.name, hasMenuItem: c.items.map((i) => ({ "@type": "MenuItem", name: i.name, description: i.description ?? undefined, image: i.imageUrl ?? undefined, offers: { "@type": "Offer", price: i.price, priceCurrency: "BDT" } })) })),
  });
  return (
    <div>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: ld }} />
      {branches.length > 1 && (
        <nav aria-label="Branch" className="mb-4 flex flex-wrap gap-2">
          {branches.map((b) => (
            <L key={b.id} href={`/menu?b=${b.id}`} className={b.id === branch.id ? "btn !min-h-[40px]" : "btn-ghost !min-h-[40px]"}>{b.name}</L>
          ))}
        </nav>
      )}
      <MenuView menu={menu} />
    </div>
  );
}
