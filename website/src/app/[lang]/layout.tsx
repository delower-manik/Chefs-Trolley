import type { Metadata } from "next";
import { notFound } from "next/navigation";
import "../globals.css";
import { getSite } from "@/lib/server-api";
import { hexToRgbChannels, safeColor } from "@/lib/format";
import { SITE_URL } from "@/lib/config";
import { isLocale } from "@/lib/locale";
import { jsonLd } from "@/lib/seo";
import type { Site } from "@/lib/types";
import { Providers } from "@/components/providers";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import CookieNotice from "@/components/CookieNotice";

const FALLBACK: Site = {
  siteName: "Restaurant", tagline: null, primaryColor: null, logoUrl: null, faviconUrl: null, contactPhone: null, contactEmail: null, address: null,
  socialFacebook: null, socialInstagram: null, locale: "en", timezone: "Asia/Dhaka", currency: { code: "BDT", symbol: "৳", decimals: 0 },
  ordering: { enabled: false, cod: false, pickup: false, minOrder: 0, online: [] }, features: { reservations: false },
};

// Rendered per request (the API may be unreachable at build time); fetches inside are still cached for 60s.
export const dynamic = "force-dynamic";

export async function generateMetadata(props: { params: Promise<{ lang: string }> }): Promise<Metadata> {
  const params = await props.params;
  const s = (await getSite()) ?? FALLBACK;
  return {
    metadataBase: new URL(SITE_URL), title: { default: s.siteName, template: `%s · ${s.siteName}` }, description: s.tagline ?? (params.lang === "bn" ? `${s.siteName} — মেনু দেখুন, অনলাইনে অর্ডার করুন ও টেবিল রিজার্ভ করুন।` : `${s.siteName} — browse the menu, order online and reserve a table.`),
    icons: s.faviconUrl ? { icon: s.faviconUrl } : undefined,
    openGraph: { siteName: s.siteName, type: "website", locale: params.lang === "bn" ? "bn_BD" : "en_US", images: s.logoUrl ? [s.logoUrl] : undefined },
    twitter: { card: "summary_large_image" },
  };
}

export default async function RootLayout({ children, ...props }: { children: React.ReactNode; params: Promise<{ lang: string }> }) {
  const params = await props.params;
  if (!isLocale(params.lang)) notFound();
  const site = (await getSite()) ?? FALLBACK;
  const color = safeColor(site.primaryColor);
  const ld = jsonLd({
    "@context": "https://schema.org", "@type": "Restaurant", name: site.siteName, description: site.tagline ?? undefined, url: `${SITE_URL}/${params.lang}`,
    telephone: site.contactPhone ?? undefined, email: site.contactEmail ?? undefined, address: site.address ?? undefined, image: site.logoUrl ?? undefined,
    sameAs: [site.socialFacebook, site.socialInstagram].filter(Boolean), inLanguage: params.lang,
  });
  return (
    <html lang={params.lang} style={color ? ({ "--brand": hexToRgbChannels(color) } as React.CSSProperties) : undefined}>
      <body>
        <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: ld }} />
        <Providers site={site} lang={params.lang}>
          <Header />
          <main className="wrap pt-6">{children}</main>
          <Footer />
          <CookieNotice />
        </Providers>
      </body>
    </html>
  );
}
