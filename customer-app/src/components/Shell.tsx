"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useApp } from "./Providers";

export default function Shell({ children }: { children: React.ReactNode }) {
  const { t, site, online, cartCount, lang, setLang } = useApp();
  const path = usePathname();
  const tabs = [{ href: "/", label: t("nav.home") }, { href: "/menu", label: t("nav.menu") }, { href: "/orders", label: t("nav.orders") }, { href: "/account", label: t("nav.account") }];
  return (
    <>
      {!online && <p className="mb-3 rounded-xl bg-amber-100 p-3 text-sm">{t("offline")}</p>}
      <header className="mb-4 flex items-center justify-between">
        <b className="text-lg">{site?.siteName ?? "…"}</b>
        <button className="text-sm underline" onClick={() => setLang(lang === "bn" ? "en" : "bn")}>{lang === "bn" ? "EN" : "বাং"}</button>
      </header>
      {children}
      {cartCount > 0 && path !== "/checkout" && (
        <Link href="/checkout" className="fixed inset-x-4 bottom-[72px] mx-auto flex max-w-md items-center justify-between rounded-2xl bg-brand px-5 py-3 font-semibold text-white shadow-lg" style={{ marginBottom: "env(safe-area-inset-bottom)" }}>
          <span>{t("menu.viewCart")}</span><span>{t("menu.items", { n: cartCount })}</span>
        </Link>
      )}
      <nav className="fixed inset-x-0 bottom-0 border-t border-line bg-white" style={{ paddingBottom: "env(safe-area-inset-bottom)" }}>
        <div className="mx-auto flex max-w-md">
          {tabs.map((x) => (
            <Link key={x.href} href={x.href} className={`flex min-h-[56px] flex-1 items-center justify-center text-sm font-semibold ${(x.href === "/" ? path === "/" : path.startsWith(x.href)) ? "text-brand" : "text-soft"}`}>{x.label}</Link>
          ))}
        </div>
      </nav>
    </>
  );
}
