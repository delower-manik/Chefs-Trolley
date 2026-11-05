"use client";

import { Suspense, useCallback, useEffect, useState } from "react";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import { api, ApiError } from "@/lib/client-api";
import { useApp } from "@/components/providers";
import { followOrder } from "@/lib/live-order";
import RequireLogin from "@/components/RequireLogin";
import type { OrderStage, PaymentInfo, WebOrder } from "@/lib/types";

const STEPS: OrderStage[] = ["received", "preparing", "ready", "on_the_way", "done"];

function Detail() {
  const { id } = useParams<{ id: string }>();
  const payment = useSearchParams().get("payment");
  const { t, money, site, addLine, path } = useApp();
  const router = useRouter();
  const [again, setAgain] = useState<string[] | null>(null);
  const [o, setO] = useState<WebOrder | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const [rating, setRating] = useState(5);
  const [comment, setComment] = useState("");
  const [reviewed, setReviewed] = useState(false);
  const load = useCallback(() => api<WebOrder>(`/web/orders/${id}`).then(setO).catch((x: ApiError) => setErr(x.message)), [id]);

  useEffect(() => { load(); }, [load]);
  // Live updates (push when the server has it, otherwise a cheap conditional poll). Stops once the order is finished.
  const finished = o?.stage === "done" || o?.stage === "cancelled";
  const started = o !== null;
  useEffect(() => {
    if (!started || finished) return;
    return followOrder(id, load);
  }, [id, started, finished, load]);

  async function cancel() {
    setErr(null);
    try { await api(`/web/orders/${id}/cancel`, { method: "POST", body: {} }); await load(); } catch (x) { setErr((x as ApiError).message); }
  }
  async function payNow(gateway: string) {
    setErr(null);
    try {
      const p = await api<PaymentInfo>(`/web/orders/${id}/pay`, { body: { gateway } });
      if (p && "redirectUrl" in p && p.redirectUrl) window.location.assign(p.redirectUrl);
    } catch (x) { setErr((x as ApiError).message); }
  }
  async function orderAgain() {
    setErr(null);
    try {
      const r = await api<{ branchId: string; lines: { itemId?: string; comboId?: string; name: string; unitPrice: number; quantity: number; modifierIds: string[]; modifierNames: string[]; notes: string | null }[]; skipped: string[] }>(`/web/orders/${id}/reorder`);
      for (const l of r.lines) addLine(r.branchId, { ...l, notes: l.notes ?? undefined });
      if (r.skipped.length) setAgain(r.skipped);
      else router.push(path("/cart"));
    } catch (x) { setErr((x as ApiError).message); }
  }
  async function review(e: React.FormEvent) {
    e.preventDefault(); setErr(null);
    try { await api(`/web/orders/${id}/review`, { body: { rating, comment: comment.trim() || undefined } }); setReviewed(true); } catch (x) { setErr((x as ApiError).message); }
  }

  if (!o) return err ? <p role="alert" className="card">{err}</p> : <p className="text-soft">{t("common.loading")}</p>;
  const idx = STEPS.indexOf(o.stage);
  const steps = o.fulfilment === "pickup" ? STEPS.filter((s) => s !== "on_the_way") : STEPS;
  return (
    <div className="mx-auto max-w-xl space-y-4">
      <h1 className="text-3xl font-bold">#{o.code}</h1>
      {payment && <p role="status" className={`rounded-xl p-3 text-sm ${payment === "success" || payment === "paid" ? "bg-green-50 text-green-800" : payment === "failed" || payment === "cancelled" ? "bg-red-50 text-red-800" : "bg-amber-50 text-amber-800"}`}>{payment === "success" || payment === "paid" ? t("pay.paid") : payment === "failed" || payment === "cancelled" ? t("pay.failed") : t("pay.pending")}</p>}
      {o.stage === "cancelled" ? <p className="card font-semibold text-red-700">{t("stage.cancelled")}</p> : (
        <ol className="card space-y-2" aria-label="Order progress">
          {steps.map((s) => { const i = STEPS.indexOf(s); const done = i <= idx; return (<li key={s} aria-current={i === idx ? "step" : undefined} className={`flex items-center gap-3 ${done ? "font-semibold text-ink" : "text-soft"}`}><span className={`h-3 w-3 rounded-full ${done ? "bg-brand" : "bg-line"}`} />{t(`stage.${s}`)}</li>); })}
        </ol>
      )}
      {o.delivery?.riderName && <p className="card text-sm">🛵 {o.delivery.riderName}</p>}
      <ul className="card space-y-1 text-sm">
        {o.items.map((i, k) => (<li key={k} className="flex justify-between gap-2"><span>{i.quantity}× {i.name}{i.modifiers.length ? ` (${i.modifiers.map((m) => m.name).join(", ")})` : ""}</span><span>{money(i.total)}</span></li>))}
        <li className="flex justify-between border-t border-line pt-1"><span>{t("co.fee")}</span><span>{money(o.deliveryFee)}</span></li>
        <li className="flex justify-between font-semibold"><span>{t("co.total")}</span><span>{money(o.total)}</span></li>
        {o.balanceDue > 0 && <li className="flex justify-between text-amber-800"><span>{t("orders.balance")}</span><span>{money(o.balanceDue)}</span></li>}
      </ul>
      {again && <p role="status" className="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">{t("orders.againSome")} {again.join(", ")}. <a className="underline" href={path("/cart")}>{t("nav.cart")}</a></p>}
      {err && <p role="alert" className="rounded-xl bg-red-50 p-3 text-sm text-red-800">{err}</p>}
      <div className="flex flex-wrap gap-2">
        {o.stage !== "cancelled" && o.stage !== "done" && o.balanceDue > 0 && site.ordering.online.map((g) => <button key={g.key} className="btn" onClick={() => payNow(g.key)}>{t("orders.payNow")} — {g.label}</button>)}
        {o.stage === "received" && <button className="btn-ghost" onClick={cancel}>{t("orders.cancel")}</button>}
        {(o.stage === "done" || o.stage === "cancelled") && site.ordering.enabled && <button className="btn-ghost" onClick={orderAgain}>{t("orders.again")}</button>}
      </div>
      {o.stage === "done" && (reviewed ? <p role="status" className="card">{t("orders.reviewSent")}</p> : (
        <form onSubmit={review} className="card space-y-3">
          <p className="font-semibold">{t("orders.review")}</p>
          <div className="flex gap-1" role="radiogroup" aria-label="Rating">{[1, 2, 3, 4, 5].map((n) => <button key={n} type="button" role="radio" aria-checked={rating === n} aria-label={`${n}`} onClick={() => setRating(n)} className={`h-11 w-11 text-2xl ${n <= rating ? "text-amber-500" : "text-line"}`}>★</button>)}</div>
          <textarea className="field py-2" rows={3} maxLength={1000} value={comment} onChange={(e) => setComment(e.target.value)} />
          <button className="btn">{t("orders.submit")}</button>
        </form>
      ))}
    </div>
  );
}
export default function OrderPage() { return (<RequireLogin next="/orders"><Suspense><Detail /></Suspense></RequireLogin>); }
