"use client";

import L from "@/components/L";
import { useApp } from "./providers";
import { otherLocale } from "@/lib/locale";

export default function Header() {
  const { site, t, locale, setLocale, customer, cartCount } = useApp();
  return (
    <header className="sticky top-0 z-30 border-b border-line bg-paper/95 backdrop-blur">
      <div className="wrap flex flex-wrap items-center gap-x-4 gap-y-2 py-3">
        <L href="/" className="mr-auto flex items-center gap-2 text-lg font-bold">
          {site.logoUrl ? /* eslint-disable-next-line @next/next/no-img-element */ <img src={site.logoUrl} alt="" className="h-8 w-8 rounded-full object-cover" /> : null}
          <span style={{ fontFamily: "ui-serif, Georgia, serif" }}>{site.siteName}</span>
        </L>
        <nav className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm font-medium" aria-label="Main">
          <L href="/menu" className="py-2 hover:text-brand">{t("nav.menu")}</L>
          {site.features.reservations && <L href="/reservations" className="py-2 hover:text-brand">{t("nav.reserve")}</L>}
          {customer ? <L href="/orders" className="py-2 hover:text-brand">{t("nav.orders")}</L> : null}
          <L href={customer ? "/account" : "/login"} className="py-2 hover:text-brand">{customer ? t("nav.account") : t("nav.signin")}</L>
          <button type="button" onClick={() => setLocale(otherLocale(locale))} className="rounded-full border border-line px-3 py-1.5 text-xs" aria-label="Language">
            {locale === "en" ? "বাংলা" : "English"}
          </button>
          <L href="/cart" className="btn !min-h-[40px] !px-4">
            {t("nav.cart")}{cartCount > 0 ? ` (${cartCount})` : ""}
          </L>
        </nav>
      </div>
    </header>
  );
}
