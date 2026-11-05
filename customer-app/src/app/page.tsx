"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { api } from "@/lib/client-api";
import type { WebOrder } from "@/lib/types";
import { useApp } from "@/components/Providers";
import Shell from "@/components/Shell";

export default function Home() {
  const { t, site, siteError, customer, ready, money } = useApp();
  const [active, setActive] = useState<WebOrder | null>(null);
  useEffect(() => {
    if (!customer) { setActive(null); return; }
    api<WebOrder[]>("/web/orders").then((o) => setActive(o.find((x) => x.stage !== "done" && x.stage !== "cancelled") ?? null)).catch(() => setActive(null));
  }, [customer]);

  return (
    <Shell>
      <main className="space-y-4">
        <section className="card">
          <h1 className="text-xl font-bold">{customer ? t("home.hello", { name: customer.name }) : t("home.welcome")}</h1>
          {site?.tagline && <p className="text-sm text-soft">{site.tagline}</p>}
          {customer && <p className="mt-1 text-sm">{t("home.points", { n: customer.loyaltyPoints })}</p>}
        </section>
        {siteError && <p role="alert" className="card text-red-700">{t("err.network")}</p>}
        {site && !site.ordering.enabled && <p role="status" className="card">{t("home.closed")}</p>}
        {active && (
          <Link href={`/orders/${active.id}`} className="card block border-brand">
            <p className="text-xs text-soft">{t("home.active")}</p>
            <p className="font-semibold">#{active.code} · {t(`stage.${active.stage}` as "stage.received")}</p>
            <p className="text-sm">{money(active.total)} · <u>{t("home.track")}</u></p>
          </Link>
        )}
        <Link href="/menu" className="btn">{t("home.order")}</Link>
        {ready && !customer && <Link href="/login" className="btn-ghost">{t("home.signin")}</Link>}
        {site?.features.reservations && <Link href="/reservations" className="btn-ghost">{t("a.reservations")}</Link>}
      </main>
    </Shell>
  );
}
