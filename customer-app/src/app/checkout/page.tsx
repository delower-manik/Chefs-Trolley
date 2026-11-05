"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { api, ApiError } from "@/lib/client-api";
import type { Address, PaymentInfo, WebOrder, Zone } from "@/lib/types";
import { API } from "@/lib/config";
import { useApp } from "@/components/Providers";
import Shell from "@/components/Shell";

export default function Checkout() {
  const { t, site, money, cart, setQty, clearCart, cartTotal, customer, ready } = useApp();
  const router = useRouter();
  const [fulfilment, setFulfilment] = useState<"delivery" | "pickup">("delivery");
  const [zones, setZones] = useState<Zone[]>([]);
  const [zone, setZone] = useState("");
  const [address, setAddress] = useState("");
  const [phone, setPhone] = useState("");
  const [promo, setPromo] = useState("");
  const [pay, setPay] = useState("");
  const [saved, setSaved] = useState<Address[]>([]);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const key = useRef<string>(crypto.randomUUID()); // one key per attempt: a double tap can never place two orders

  useEffect(() => { if (site) setPay((p) => p || (site.ordering.cod ? "cod" : site.ordering.online[0]?.key ?? "")); }, [site]);
  useEffect(() => { if (cart.branchId) fetch(`${API}/web/branches/${cart.branchId}/menu`).then((r) => r.json()).then((j) => setZones(j.data?.deliveryZones ?? [])).catch(() => setZones([])); }, [cart.branchId]);
  useEffect(() => { if (customer) { setPhone((p) => p || customer.phone); setAddress((a) => a || customer.address || ""); api<Address[]>("/web/addresses").then((a) => { setSaved(a); const d = a.find((x) => x.isDefault); if (d) setAddress((cur) => cur || d.address); }).catch(() => setSaved([])); } }, [customer]);
  const fee = useMemo(() => (fulfilment === "delivery" ? zones.find((z) => z.id === zone)?.fee ?? 0 : 0), [fulfilment, zone, zones]);

  if (!cart.lines.length) return <Shell><div className="card text-center"><p>{t("cart.empty")}</p><Link href="/menu" className="btn mt-4">{t("nav.menu")}</Link></div></Shell>;
  if (site && !site.ordering.enabled) return <Shell><p role="alert" className="card">{t("home.closed")}</p></Shell>;
  if (ready && !customer) return <Shell><div className="card text-center"><p>{t("co.signin")}</p><Link href="/login?next=/checkout" className="btn mt-4">{t("login.send")}</Link></div></Shell>;

  const min = site?.ordering.minOrder ?? 0;
  const tooSmall = min > 0 && cartTotal < min;
  const online = pay !== "" && pay !== "cod";

  async function place(e: React.FormEvent) {
    e.preventDefault(); setErr(null);
    if (tooSmall) return setErr(t("co.minOrder", { amount: money(min) }));
    setBusy(true);
    try {
      const o = await api<WebOrder & { payment?: PaymentInfo }>("/web/orders", {
        idempotencyKey: key.current,
        body: {
          branch_id: cart.branchId, fulfilment, payment: online ? "online" : "cod", gateway: online ? pay : undefined,
          delivery_zone_id: fulfilment === "delivery" ? zone || undefined : undefined, delivery_address: fulfilment === "delivery" ? address : undefined,
          phone, promo_code: promo.trim() || undefined,
          items: cart.lines.map((l) => ({ food_item_id: l.itemId, combo_id: l.comboId, quantity: l.quantity, notes: l.notes, modifier_ids: l.modifierIds })),
        },
      });
      clearCart();
      const p = o.payment;
      if (online && p && "redirectUrl" in p && p.redirectUrl) window.location.assign(p.redirectUrl);
      else router.replace(`/orders/${o.id}`);
    } catch (x) {
      const a = x as ApiError;
      setErr(a.fieldErrors ? Object.values(a.fieldErrors).flat()[0] ?? a.message : a.message);
      key.current = crypto.randomUUID(); // a failed attempt may be corrected and retried as a new request
    } finally { setBusy(false); }
  }

  return (
    <Shell>
      <form onSubmit={place} className="space-y-4">
        <h1 className="text-xl font-bold">{t("cart.title")}</h1>
        <ul className="card space-y-2 text-sm">{cart.lines.map((l) => (
          <li key={l.key} className="flex items-center justify-between gap-2">
            <span>{l.name}{l.modifierNames.length ? ` (${l.modifierNames.join(", ")})` : ""}{l.notes ? <i className="block text-xs text-soft">{l.notes}</i> : null}</span>
            <span className="flex items-center gap-2"><button type="button" className="btn-ghost !min-h-[44px] !w-11 !px-0" aria-label="-" onClick={() => setQty(l.key, l.quantity - 1)}>−</button><b>{l.quantity}</b><button type="button" className="btn-ghost !min-h-[44px] !w-11 !px-0" aria-label="+" onClick={() => setQty(l.key, l.quantity + 1)}>+</button></span>
          </li>))}</ul>
        <div className="grid grid-cols-2 gap-2" role="radiogroup">
          <button type="button" className={fulfilment === "delivery" ? "btn" : "btn-ghost"} onClick={() => setFulfilment("delivery")}>{t("co.delivery")}</button>
          {site?.ordering.pickup && <button type="button" className={fulfilment === "pickup" ? "btn" : "btn-ghost"} onClick={() => setFulfilment("pickup")}>{t("co.pickup")}</button>}
        </div>
        {fulfilment === "delivery" && (
          <div className="card space-y-3">
            <div><label className="label" htmlFor="z">{t("co.zone")}</label><select id="z" className="field" required value={zone} onChange={(e) => setZone(e.target.value)}><option value="" disabled>—</option>{zones.map((z) => <option key={z.id} value={z.id}>{z.name} · {money(z.fee)}</option>)}</select></div>
            {saved.length > 0 && <div><label className="label" htmlFor="sa">{t("co.saved")}</label><select id="sa" className="field" value="" onChange={(e) => { const a = saved.find((x) => x.id === e.target.value); if (a) setAddress(a.address); }}><option value="">—</option>{saved.map((a) => <option key={a.id} value={a.id}>{a.label}: {a.address}</option>)}</select></div>}
            <div><label className="label" htmlFor="a">{t("co.address")}</label><textarea id="a" className="field py-2" rows={2} required maxLength={255} value={address} onChange={(e) => setAddress(e.target.value)} /></div>
          </div>
        )}
        <div className="card space-y-3">
          <div><label className="label" htmlFor="p">{t("co.phone")}</label><input id="p" className="field" inputMode="tel" required value={phone} onChange={(e) => setPhone(e.target.value)} /></div>
          <div><label className="label" htmlFor="pc">{t("co.promo")}</label><input id="pc" className="field" value={promo} onChange={(e) => setPromo(e.target.value)} maxLength={40} /></div>
          <div><label className="label" htmlFor="pm">{t("co.pay")}</label><select id="pm" className="field" value={pay} onChange={(e) => setPay(e.target.value)}>{site?.ordering.cod && <option value="cod">{t("co.cod")}</option>}{site?.ordering.online.map((o) => <option key={o.key} value={o.key}>{o.label}</option>)}</select></div>
        </div>
        <div className="card text-sm"><p className="flex justify-between"><span>{t("co.subtotal")}</span><span>{money(cartTotal)}</span></p>{fulfilment === "delivery" && <p className="flex justify-between"><span>{t("co.fee")}</span><span>{money(fee)}</span></p>}
          <p className="flex justify-between font-semibold"><span>{t("co.total")}</span><span>{money(cartTotal + fee)}</span></p><p className="mt-1 text-xs text-soft">{t("co.estimate")}</p></div>
        {err && <p role="alert" className="text-sm text-red-700">{err}</p>}
        <button className="btn" disabled={busy || !pay}>{busy ? t("loading") : t("co.place")}</button>
      </form>
    </Shell>
  );
}
