"use client";

import L from "@/components/L";
import { useEffect, useMemo, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { api, ApiError } from "@/lib/client-api";
import { API } from "@/lib/config";
import { useApp } from "@/components/providers";
import type { Address, Menu, PaymentInfo, WebOrder } from "@/lib/types";

export default function CheckoutPage() {
  const { t, money, site, cart, cartTotal, clearCart, customer, authReady, path } = useApp();
  const router = useRouter();
  const [zones, setZones] = useState<Menu["deliveryZones"]>([]);
  const [fulfilment, setFulfilment] = useState<"delivery" | "pickup">(site.ordering.cod || site.ordering.online.length ? "delivery" : "pickup");
  const [zone, setZone] = useState("");
  const [address, setAddress] = useState("");
  const [phone, setPhone] = useState("");
  const [promo, setPromo] = useState("");
  const [pay, setPay] = useState<string>(site.ordering.cod ? "cod" : site.ordering.online[0]?.key ?? "");
  const [saved, setSaved] = useState<Address[]>([]);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const key = useRef<string>(crypto.randomUUID()); // one key per checkout attempt: a double tap can never place two orders

  useEffect(() => { if (cart.branchId) fetch(`${API}/web/branches/${cart.branchId}/menu`).then((r) => r.json()).then((j) => setZones(j.data?.deliveryZones ?? [])).catch(() => setZones([])); }, [cart.branchId]);
  useEffect(() => { if (customer) api<Address[]>("/web/addresses").then((a) => { setSaved(a); const d = a.find((x) => x.isDefault); if (d) setAddress((cur) => cur || d.address); }).catch(() => setSaved([])); }, [customer]);
  useEffect(() => { if (customer) { setPhone((p) => p || customer.phone); setAddress((a) => a || customer.address || ""); } }, [customer]);
  const fee = useMemo(() => (fulfilment === "delivery" ? zones.find((z) => z.id === zone)?.fee ?? 0 : 0), [fulfilment, zone, zones]);

  if (!cart.lines.length) return (<div className="card text-center"><p>{t("cart.empty")}</p><L href="/menu" className="btn mt-4">{t("nav.menu")}</L></div>);
  if (!site.ordering.enabled) return <p role="alert" className="card">{t("home.closed")}</p>;
  if (authReady && !customer) return (<div className="card text-center"><p>{t("co.signinFirst")}</p><L href="/login?next=/checkout" className="btn mt-4">{t("nav.signin")}</L></div>);

  const tooSmall = site.ordering.minOrder > 0 && cartTotal < site.ordering.minOrder;
  const online = pay !== "cod";

  async function place(e: React.FormEvent) {
    e.preventDefault(); setErr(null);
    if (tooSmall) return setErr(t("co.minOrder", { amount: money(site.ordering.minOrder) }));
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
      else router.replace(path(`/orders/${o.id}`));
    } catch (x) {
      const a = x as ApiError;
      setErr(a.fieldErrors ? Object.values(a.fieldErrors).flat()[0] ?? a.message : a.message);
      key.current = crypto.randomUUID(); // a failed attempt may be corrected and retried as a new request
    } finally { setBusy(false); }
  }

  return (
    <form onSubmit={place} className="mx-auto max-w-xl space-y-4">
      <h1 className="text-3xl font-bold">{t("co.title")}</h1>
      <div className="flex gap-2" role="radiogroup">
        <button type="button" className={fulfilment === "delivery" ? "btn flex-1" : "btn-ghost flex-1"} onClick={() => setFulfilment("delivery")}>{t("co.delivery")}</button>
        {site.ordering.pickup && <button type="button" className={fulfilment === "pickup" ? "btn flex-1" : "btn-ghost flex-1"} onClick={() => setFulfilment("pickup")}>{t("co.pickup")}</button>}
      </div>
      {fulfilment === "delivery" && (
        <div className="card space-y-3">
          <div><label className="label" htmlFor="z">{t("co.zone")}</label>
            <select id="z" className="field" required value={zone} onChange={(e) => setZone(e.target.value)}><option value="" disabled>—</option>{zones.map((z) => <option key={z.id} value={z.id}>{z.name} · {money(z.fee)}</option>)}</select></div>
          {saved.length > 0 && (
            <div><label className="label" htmlFor="sa">{t("co.saved")}</label>
              <select id="sa" className="field" value="" onChange={(e) => { const a = saved.find((x) => x.id === e.target.value); if (a) setAddress(a.address); }}>
                <option value="">—</option>{saved.map((a) => <option key={a.id} value={a.id}>{a.label}: {a.address}</option>)}
              </select></div>
          )}
          <div><label className="label" htmlFor="a">{t("co.address")}</label><textarea id="a" className="field py-2" rows={2} required maxLength={255} value={address} onChange={(e) => setAddress(e.target.value)} /></div>
        </div>
      )}
      <div className="card space-y-3">
        <div><label className="label" htmlFor="p">{t("co.phone")}</label><input id="p" className="field" inputMode="tel" required value={phone} onChange={(e) => setPhone(e.target.value)} /></div>
        <div><label className="label" htmlFor="pr">{t("co.promo")}</label><input id="pr" className="field uppercase" maxLength={40} value={promo} onChange={(e) => setPromo(e.target.value)} /></div>
      </div>
      <fieldset className="card">
        <legend className="px-1 font-semibold">{t("co.payment")}</legend>
        {site.ordering.cod && <label className="flex min-h-[44px] items-center gap-3"><input type="radio" name="pay" className="h-5 w-5 accent-brand" checked={pay === "cod"} onChange={() => setPay("cod")} />{t("co.cod")}</label>}
        {site.ordering.online.map((g) => <label key={g.key} className="flex min-h-[44px] items-center gap-3"><input type="radio" name="pay" className="h-5 w-5 accent-brand" checked={pay === g.key} onChange={() => setPay(g.key)} />{t("co.online")} — {g.label}</label>)}
      </fieldset>
      <div className="card space-y-1 text-sm">
        <div className="flex justify-between"><span>{t("cart.subtotal")}</span><span>{money(cartTotal)}</span></div>
        {fulfilment === "delivery" && <div className="flex justify-between"><span>{t("co.fee")}</span><span>{money(fee)}</span></div>}
        <div className="flex justify-between text-base font-semibold"><span>{t("co.total")}</span><span>{money(cartTotal + fee)}</span></div>
        <p className="pt-1 text-xs text-soft">{t("co.estimateNote")}</p>
      </div>
      {err && <p role="alert" className="rounded-xl bg-red-50 p-3 text-sm text-red-800">{err}</p>}
      <button className="btn w-full" disabled={busy || !pay}>{busy ? t("co.placing") : t("co.place")}</button>
    </form>
  );
}
