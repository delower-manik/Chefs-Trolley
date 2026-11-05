"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { api, ApiError } from "@/lib/api";
import { money, telHref } from "@/lib/format";
import type { Delivery } from "@/lib/types";
import { useApp } from "@/components/Providers";

export default function DeliveryPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { t, locale, profile } = useApp();
  const [d, setD] = useState<Delivery | null>(null);
  const [mode, setMode] = useState<"view" | "fail">("view");
  const [confirmed, setConfirmed] = useState(false);
  const [recipient, setRecipient] = useState("");
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  const load = useCallback(async () => {
    try { setD(await api<Delivery>(`/rider/deliveries/${id}`)); } catch (e) { setErr(e instanceof ApiError && e.status ? e.message : t("err.network")); }
  }, [id, t]);
  useEffect(() => { if (profile) void load(); }, [profile, load]);

  async function act(path: string, body?: unknown, leave = false) {
    setBusy(true); setErr(null);
    try { await api(`/rider/deliveries/${id}/${path}`, { body: body ?? {} }); if (leave) router.replace("/"); else await load(); }
    catch (e) {
      if (e instanceof ApiError && e.code === "cash_mismatch") { setConfirmed(false); await load(); } // our figure was stale: show the new amount, make them confirm again
      setErr(e instanceof ApiError && e.status ? e.message : t("err.network"));
    } finally { setBusy(false); }
  }

  if (!d) return <main><Link href="/" className="underline">{t("d.back")}</Link><p className="mt-4">{err ?? t("loading")}</p></main>;
  const open = d.status === "assigned" || d.status === "picked_up";
  const tel = telHref(d.customerPhone);
  const due = d.cashToCollect;

  return (
    <main className="space-y-4">
      <Link href="/" className="underline">← {t("d.back")}</Link>
      <h1 className="text-xl font-bold">#{d.orderCode}</h1>
      {d.cancelled && <p className="rounded-xl bg-red-100 p-3 text-sm">{t("list.cancelledNote")}</p>}
      <section className="card space-y-2">
        <p className="text-xs text-soft">{t("d.address")}</p><p>{d.address}</p>
        {d.mapUrl && <a className="btn-ghost" href={d.mapUrl} target="_blank" rel="noreferrer">{t("d.navigate")}</a>}
        {open && tel && <a className="btn-ghost" href={tel}>{t("d.call")}{d.customerName ? ` · ${d.customerName}` : ""}</a>}
      </section>
      <section className="card">
        <p className="mb-1 text-xs text-soft">{t("d.items")}</p>
        <ul className="text-sm">{d.items.map((i, n) => <li key={n}>{i.quantity}× {i.name}{i.notes ? ` (${i.notes})` : ""}</li>)}</ul>
      </section>
      {open && (
        <section className="card">
          {due > 0 ? (<><p className="text-xs text-soft">{t("d.collect")}</p><p className="text-3xl font-bold">{money(due, locale)}</p></>) : <p>{t("d.paid")}</p>}
        </section>
      )}
      {err && <p role="alert" className="text-sm text-red-700">{err}</p>}

      {open && !d.cancelled && mode === "view" && (
        <div className="space-y-3">
          {d.status === "assigned" && <button className="btn" disabled={busy} onClick={() => act("pickup")}>{t("d.pickup")}</button>}
          {d.status === "picked_up" && (<>
            {due > 0 && <label className="flex items-center gap-3 text-sm"><input type="checkbox" className="h-5 w-5" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} />{t("d.confirmCash", { amount: money(due, locale) })}</label>}
            <input className="field" placeholder={t("d.recipient")} value={recipient} onChange={(e) => setRecipient(e.target.value)} maxLength={150} />
            <button className="btn" disabled={busy || (due > 0 && !confirmed)} onClick={() => act("deliver", { cash_collected: due > 0 ? due : undefined, recipient_name: recipient || undefined }, true)}>{t("d.deliver")}</button>
          </>)}
          <button className="btn-danger" onClick={() => setMode("fail")}>{t("d.fail")}</button>
        </div>
      )}
      {open && mode === "fail" && (
        <div className="space-y-3">
          <textarea className="field py-3" rows={3} placeholder={t("d.reason")} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={300} />
          <button className="btn-danger" disabled={busy || reason.trim().length < 3} onClick={() => act("fail", { reason: reason.trim() }, true)}>{t("d.sure")}</button>
          <button className="btn-ghost" onClick={() => setMode("view")}>{t("d.back")}</button>
        </div>
      )}
      {!open && d.failureReason && <p className="text-sm">{d.failureReason}</p>}
    </main>
  );
}
