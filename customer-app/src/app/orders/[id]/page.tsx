"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { api, ApiError } from "@/lib/client-api";
import { followOrder } from "@/lib/live-order";
import type { OrderStage, PaymentInfo, WebOrder } from "@/lib/types";
import { useApp } from "@/components/Providers";
import Shell from "@/components/Shell";

const STEPS: OrderStage[] = ["received", "preparing", "ready", "on_the_way", "done"];
type Reorder = { branchId: string; lines: { itemId?: string; comboId?: string; name: string; unitPrice: number; quantity: number; modifierIds: string[]; modifierNames: string[]; notes: string | null }[]; skipped: string[] };

export default function OrderPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { t, money, addLine, customer, ready, site } = useApp();
  const [o, setO] = useState<WebOrder | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const [skipped, setSkipped] = useState<string[]>([]);
  const [rating, setRating] = useState(5);
  const [comment, setComment] = useState("");
  const [reviewed, setReviewed] = useState(false);

  const load = useCallback(() => api<WebOrder>(`/web/orders/${id}`).then(setO).catch((x: ApiError) => setErr(x.message)), [id]);
  useEffect(() => { if (customer) void load(); }, [customer, load]);
  const finished = o?.stage === "done" || o?.stage === "cancelled";
  const started = o !== null;
  useEffect(() => { if (!started || finished) return; return followOrder(id, load); }, [id, started, finished, load]);

  async function act(fn: () => Promise<unknown>) { setErr(null); try { await fn(); } catch (x) { setErr(x instanceof ApiError ? x.message : t("err.network")); } }
  const cancel = () => act(async () => { await api(`/web/orders/${id}/cancel`, { method: "POST", body: {} }); await load(); });
  const payNow = (gateway: string) => act(async () => { const p = await api<PaymentInfo>(`/web/orders/${id}/pay`, { body: { gateway } }); if (p && "redirectUrl" in p && p.redirectUrl) window.location.assign(p.redirectUrl); });
  const again = () => act(async () => {
    const r = await api<Reorder>(`/web/orders/${id}/reorder`);
    for (const l of r.lines) addLine(r.branchId, { ...l, notes: l.notes ?? undefined });
    if (r.skipped.length) setSkipped(r.skipped); else router.push("/checkout");
  });
  const review = (e: React.FormEvent) => { e.preventDefault(); void act(async () => { await api(`/web/orders/${id}/review`, { body: { rating, comment: comment.trim() || undefined } }); setReviewed(true); }); };

  if (ready && !customer) return <Shell><div className="card text-center"><Link href={`/login?next=/orders/${id}`} className="btn">{t("login.send")}</Link></div></Shell>;
  if (!o) return <Shell>{err ? <p role="alert" className="card">{err}</p> : <p className="text-soft">{t("loading")}</p>}</Shell>;
  const idx = STEPS.indexOf(o.stage);
  const steps = o.fulfilment === "pickup" ? STEPS.filter((s) => s !== "on_the_way") : STEPS;
  const cancellable = o.stage === "received";

  return (
    <Shell>
      <main className="space-y-3">
        <h1 className="text-xl font-bold">#{o.code}</h1>
        {o.stage === "cancelled" ? <p className="card font-semibold text-red-700">{t("stage.cancelled")}</p> : (
          <ol className="card space-y-2" aria-label="Order progress">
            {steps.map((s) => { const i = STEPS.indexOf(s); const done = i <= idx; return <li key={s} aria-current={i === idx ? "step" : undefined} className={`flex items-center gap-3 ${done ? "font-semibold" : "text-soft"}`}><span className={`h-3 w-3 rounded-full ${done ? "bg-brand" : "bg-line"}`} />{t(`stage.${s}` as "stage.received")}</li>; })}
          </ol>
        )}
        {o.delivery?.riderName && <p className="card text-sm">🛵 {t("o.rider", { name: o.delivery.riderName })}</p>}
        <ul className="card space-y-1 text-sm">
          {o.items.map((i, k) => <li key={k} className="flex justify-between gap-2"><span>{i.quantity}× {i.name}{i.modifiers.length ? ` (${i.modifiers.map((m) => m.name).join(", ")})` : ""}</span><span>{money(i.total)}</span></li>)}
          <li className="flex justify-between border-t border-line pt-1"><span>{t("co.fee")}</span><span>{money(o.deliveryFee)}</span></li>
          <li className="flex justify-between font-semibold"><span>{t("o.total")}</span><span>{money(o.total)}</span></li>
          {o.balanceDue > 0 ? <li className="flex justify-between text-amber-700"><span>{t("o.due")}</span><span>{money(o.balanceDue)}</span></li> : <li className="flex justify-between text-green-700"><span>{t("o.paid")}</span><span>{money(o.paid)}</span></li>}
        </ul>
        {err && <p role="alert" className="text-sm text-red-700">{err}</p>}
        {skipped.length > 0 && <p role="status" className="card text-sm">{t("o.skipped", { names: skipped.join(", ") })} <Link href="/checkout" className="underline">{t("menu.viewCart")}</Link></p>}
        {o.balanceDue > 0 && o.stage !== "cancelled" && site?.ordering.online.map((g) => <button key={g.key} className="btn" onClick={() => payNow(g.key)}>{t("o.pay")} · {g.label}</button>)}
        {cancellable && <button className="btn-danger" onClick={cancel}>{t("o.cancel")}</button>}
        {(o.stage === "done" || o.stage === "cancelled") && <button className="btn-ghost" onClick={again}>{t("o.again")}</button>}
        {o.stage === "done" && (reviewed ? <p className="card text-sm">{t("o.thanks")}</p> : (
          <form onSubmit={review} className="card space-y-2"><p className="font-semibold">{t("o.review")}</p>
            <div className="flex gap-1" role="radiogroup">{[1, 2, 3, 4, 5].map((n) => <button key={n} type="button" role="radio" aria-checked={rating === n} aria-label={`${n}`} onClick={() => setRating(n)} className={`min-h-[44px] flex-1 rounded-xl border text-lg ${n <= rating ? "border-brand text-brand" : "border-line text-soft"}`}>★</button>)}</div>
            <textarea className="field py-2" rows={2} maxLength={500} value={comment} onChange={(e) => setComment(e.target.value)} />
            <button className="btn">{t("o.send")}</button></form>
        ))}
      </main>
    </Shell>
  );
}
