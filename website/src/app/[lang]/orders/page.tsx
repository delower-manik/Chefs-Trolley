"use client";

import L from "@/components/L";
import { useEffect, useState } from "react";
import { api } from "@/lib/client-api";
import { useApp } from "@/components/providers";
import RequireLogin from "@/components/RequireLogin";
import type { WebOrder } from "@/lib/types";

function List() {
  const { t, money } = useApp();
  const [orders, setOrders] = useState<WebOrder[] | null>(null);
  const [err, setErr] = useState(false);
  useEffect(() => { api<WebOrder[]>("/web/orders").then(setOrders).catch(() => setErr(true)); }, []);
  if (err) return <p role="alert" className="card">{t("common.error")}</p>;
  if (!orders) return <p className="text-soft">{t("common.loading")}</p>;
  return (
    <div>
      <h1 className="mb-4 text-3xl font-bold">{t("orders.title")}</h1>
      {!orders.length && <p className="card">{t("orders.none")}</p>}
      <ul className="space-y-3">
        {orders.map((o) => (
          <li key={o.id} className="card flex items-center justify-between gap-3">
            <div><p className="font-semibold">#{o.code}</p><p className="text-sm text-soft">{new Date(o.createdAt).toLocaleString()} · {t(`stage.${o.stage}`)}</p></div>
            <div className="text-right"><p className="font-semibold">{money(o.total)}</p><L className="text-sm text-brand underline" href={`/orders/${o.id}`}>{t("orders.track")}</L></div>
          </li>
        ))}
      </ul>
    </div>
  );
}
export default function OrdersPage() { return (<RequireLogin next="/orders"><List /></RequireLogin>); }
