"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { api } from "@/lib/client-api";
import type { WebOrder } from "@/lib/types";
import { useApp } from "@/components/Providers";
import Shell from "@/components/Shell";

export default function Orders() {
  const { t, money, customer, ready, lang } = useApp();
  const [orders, setOrders] = useState<WebOrder[] | null>(null);
  const [err, setErr] = useState(false);
  useEffect(() => { if (customer) api<WebOrder[]>("/web/orders").then(setOrders).catch(() => setErr(true)); }, [customer]);
  if (ready && !customer) return <Shell><div className="card text-center"><p>{t("home.signin")}</p><Link href="/login?next=/orders" className="btn mt-4">{t("login.send")}</Link></div></Shell>;
  return (
    <Shell>
      <main className="space-y-3">
        {err && <p role="alert" className="text-sm text-red-700">{t("err.network")}</p>}
        {orders && orders.length === 0 && <p className="text-soft">{t("o.empty")}</p>}
        {orders?.map((o) => (
          <Link key={o.id} href={`/orders/${o.id}`} className="card block">
            <div className="flex justify-between"><b>#{o.code}</b><span className="text-sm">{t(`stage.${o.stage}` as "stage.received")}</span></div>
            <p className="text-xs text-soft">{new Date(o.createdAt).toLocaleString(lang === "bn" ? "bn-BD" : "en-BD")} · {money(o.total)}</p>
          </Link>
        ))}
      </main>
    </Shell>
  );
}
