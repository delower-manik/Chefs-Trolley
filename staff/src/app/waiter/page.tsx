"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { api, ApiError } from "@/lib/api";
import { money } from "@/lib/format";
import type { Catalog, Order } from "@/lib/types";
import { useApp } from "@/components/Providers";
import Shell from "@/components/Shell";

export default function Waiter() {
  const { t, locale } = useApp();
  const [tables, setTables] = useState<Catalog["tables"]>([]);
  const [ready, setReady] = useState<Order[]>([]);
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setErr(null);
      const [c, o] = await Promise.all([api<Catalog>("/pos/catalog"), api<{ data: Order[] }>("/orders?per_page=50").catch(() => null)]);
      setTables(c.tables);
      // `api` unwraps `data`; /orders also has `meta`, so unwrap defensively.
      const list = Array.isArray(o) ? (o as unknown as Order[]) : o?.data ?? [];
      setReady(list.filter((x) => x.status !== "cancelled" && x.items.some((i) => i.kitchenState === "ready")));
    } catch (e) { setErr(e instanceof ApiError && e.status ? e.message : t("err.network")); }
  }, [t]);
  useEffect(() => { void load(); }, [load]);
  useEffect(() => {
    const v = () => { if (document.visibilityState === "visible") void load(); };
    document.addEventListener("visibilitychange", v);
    const id = setInterval(v, 20000);
    return () => { document.removeEventListener("visibilitychange", v); clearInterval(id); };
  }, [load]);

  async function serve(o: Order) {
    setBusy(o.id);
    try { await api(`/staff/orders/${o.id}/served`, { body: {} }); await load(); }
    catch (e) { setErr(e instanceof ApiError && e.status ? e.message : t("err.network")); }
    finally { setBusy(null); }
  }
  const tableOf = (o: Order) => tables.find((x) => x.id === o.diningTableId)?.label;

  return (
    <Shell>
      <main className="space-y-4">
        {err && <p role="alert" className="text-sm text-red-700">{err}</p>}
        <section>
          <h2 className="mb-2 font-semibold">{t("w.ready")}</h2>
          {ready.length === 0 ? <p className="text-sm text-soft">{t("w.nothingReady")}</p> : (
            <ul className="space-y-2">{ready.map((o) => (
              <li key={o.id} className="card flex items-center justify-between gap-3 border-green-500">
                <div><b>#{o.code}</b>{tableOf(o) ? ` · ${tableOf(o)}` : ""}<p className="text-xs text-soft">{o.items.filter((i) => i.kitchenState === "ready").map((i) => `${i.quantity}× ${i.name}`).join(", ")}</p></div>
                <button className="btn !w-auto" disabled={busy === o.id} onClick={() => serve(o)}>{t("w.served")}</button>
              </li>
            ))}</ul>
          )}
        </section>
        <section className="grid grid-cols-2 gap-3">
          {tables.map((tb) => (
            <Link key={tb.id} href={`/waiter/table/${tb.id}`} className={`card block min-h-[84px] active:bg-black/5 ${tb.openOrder ? "border-brand" : ""}`}>
              <b>{tb.label}</b>
              <p className="text-xs text-soft">{tb.openOrder ? `${t("w.open")} · ${money(tb.openOrder.grandTotal, locale)}` : t("w.free")}{tb.capacity ? ` · ${t("w.seats", { n: tb.capacity })}` : ""}</p>
            </Link>
          ))}
        </section>
      </main>
    </Shell>
  );
}
