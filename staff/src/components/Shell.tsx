"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useApp } from "./Providers";

/** Page frame with the bottom tab bar; only the tabs this person is allowed to use are shown. */
export default function Shell({ children }: { children: React.ReactNode }) {
  const { t, can, employee, online, lang, setLang } = useApp();
  const path = usePathname();
  if (!employee) return <p>{t("loading")}</p>;
  const tabs = [
    can("orders.create") && { href: "/waiter", label: t("tab.tables") },
    can("kds.access") && { href: "/kitchen", label: t("tab.kitchen") },
    { href: "/attendance", label: t("tab.me") },
  ].filter(Boolean) as { href: string; label: string }[];
  return (
    <>
      {!online && <p className="mb-3 rounded-xl bg-amber-100 p-3 text-sm">{t("offline")}</p>}
      <header className="mb-4 flex items-center justify-between">
        <b>{employee.name}</b>
        <button className="text-sm underline" onClick={() => setLang(lang === "bn" ? "en" : "bn")}>{lang === "bn" ? "EN" : "বাং"}</button>
      </header>
      {children}
      <nav className="fixed inset-x-0 bottom-0 border-t border-line bg-white" style={{ paddingBottom: "env(safe-area-inset-bottom)" }}>
        <div className="mx-auto flex max-w-md">
          {tabs.map((x) => (
            <Link key={x.href} href={x.href} className={`flex min-h-[56px] flex-1 items-center justify-center text-sm font-semibold ${path.startsWith(x.href) ? "text-brand" : "text-soft"}`}>{x.label}</Link>
          ))}
        </div>
      </nav>
    </>
  );
}
