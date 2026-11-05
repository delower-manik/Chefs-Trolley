import L from "@/components/L";
import type { Metadata } from "next";
import { getBranches, getPage, getReviews, getSite } from "@/lib/server-api";
import { isLocale } from "@/lib/locale";
import { alternates } from "@/lib/seo";
import Sections from "@/components/Sections";
import Stars from "@/components/Stars";

export async function generateMetadata(props: { params: Promise<{ lang: string }> }): Promise<Metadata> {
  const params = await props.params;
  const p = await getPage("home");
  const alt = isLocale(params.lang) ? alternates(params.lang, "/") : undefined;
  return p ? { title: p.metaTitle ?? undefined, description: p.metaDescription ?? undefined, openGraph: p.ogImageUrl ? { images: [p.ogImageUrl] } : undefined, alternates: alt } : { alternates: alt };
}

export default async function Home() {
  const [site, page, branches] = await Promise.all([getSite(), getPage("home"), getBranches()]);
  const reviews = branches[0] ? await getReviews(branches[0].id) : null;
  return (
    <div className="space-y-10">
      {page && page.sections.length > 0 ? (
        <Sections sections={page.sections} />
      ) : (
        <section className="rounded-3xl bg-brand/10 px-6 py-14 text-center">
          <h1 className="text-4xl font-bold sm:text-5xl">{site?.siteName ?? "Welcome"}</h1>
          {site?.tagline && <p className="mx-auto mt-3 max-w-xl text-soft">{site.tagline}</p>}
          <L href="/menu" className="btn mt-6">Menu</L>
        </section>
      )}
      {site && !site.ordering.enabled && <p role="status" className="card text-center text-sm text-soft">Online ordering is currently closed. You can still browse the menu or call us.</p>}
      {reviews && reviews.count > 0 && (
        <section aria-labelledby="rv">
          <h2 id="rv" className="mb-3 text-2xl font-bold">★ {reviews.average.toFixed(1)} <span className="text-base font-normal text-soft">({reviews.count})</span></h2>
          <div className="grid gap-3 sm:grid-cols-2">
            {reviews.reviews.slice(0, 4).map((r, i) => (
              <figure key={i} className="card">
                <Stars value={r.rating} />
                {r.comment && <blockquote className="mt-1 text-sm">{r.comment}</blockquote>}
                <figcaption className="mt-1 text-xs text-soft">{r.name ?? "Guest"}</figcaption>
              </figure>
            ))}
          </div>
        </section>
      )}
    </div>
  );
}
