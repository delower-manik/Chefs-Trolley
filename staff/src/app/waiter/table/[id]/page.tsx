"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { api, ApiError } from "@/lib/api";
import { cartPayload, cartTotal, groupsOk, lineKey, money, type Cart } from "@/lib/format";
import type { Catalog, CatalogItem, Order } from "@/lib/types";
import { useApp } from "@/components/Providers";
import Shell from "@/components/Shell";

export default function TablePage() {
  const { id } = useParams<{ id: string }>();
  const { t, locale } = useApp();
  const [cat, setCat] = useState<Catalog | null>(null);
  const [order, setOrder] = useState<Order | null>(null);
  const [cart, setCart] = useState<Cart>({});
  const [category, setCategory] = useState<string>("");
  const [picking, setPicking] = useState<CatalogItem | null>(null);
  const [picked, setPicked] = useState<string[]>([]);
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const idem = useRef<string | null>(null); // one key per cart: a double tap or a retry after a bad network can never create two orders

  const load = useCallback(async () => {
    try {
      const c = await api<Catalog>("/pos/catalog");
      setCat(c);
      setCategory((x) => x || c.categories[0]?.id || "");
      const open = c.tables.find((x) => x.id === id)?.openOrder;
      setOrder(open ? await api<Order>(`/orders/${open.id}`) : null);
    } catch (e) { setErr(e instanceof ApiError && e.status ? e.message : t("err.network")); }
  }, [id, t]);
  useEffect(() => { void load(); }, [load]);

  const table = cat?.tables.find((x) => x.id === id);
  const items = useMemo(() => cat?.items.filter((i) => !category || i.categoryId === category) ?? [], [cat, category]);
  const priceOf = useCallback((itemId: string, mods: string[]) => {
    const it = cat?.items.find((x) => x.id === itemId);
    if (!it) return 0;
    return it.basePrice + it.modifierGroups.flatMap((g) => g.modifiers).filter((m) => mods.includes(m.id)).reduce((s, m) => s + m.price, 0);
  }, [cat]);

  function startAdd(it: CatalogItem) {
    setMsg(null);
    if (it.modifierGroups.length === 0) return addLine(it, [], "");
    setPicking(it); setPicked([]); setNote("");
  }
  function addLine(it: CatalogItem, mods: string[], n: string) {
    const k = lineKey(it.id, mods, n);
    idem.current = null; // the cart changed: a new submission, a new key
    setCart((c) => ({ ...c, [k]: { itemId: it.id, modifierIds: mods, notes: n.trim(), quantity: (c[k]?.quantity ?? 0) + 1 } }));
    setPicking(null);
  }
  const bump = (k: string, d: number) => { idem.current = null; setCart((c) => { const q = (c[k]?.quantity ?? 0) + d; const { [k]: _gone, ...rest } = c; void _gone; return q <= 0 ? rest : { ...rest, [k]: { ...c[k], quantity: q } }; }); };

  async function send() {
    const payload = cartPayload(cart);
    if (!payload.length) return;
    setBusy(true); setErr(null);
    idem.current ??= crypto.randomUUID();
    try {
      const headers = { "Idempotency-Key": idem.current };
      if (order) await api(`/orders/${order.id}/items`, { body: { items: payload }, headers });
      else await api("/orders", { body: { source: "dine_in", dining_table_id: id, items: payload }, headers });
      setCart({}); idem.current = null; setMsg(t("t.sent")); await load();
    } catch (e) { setErr(e instanceof ApiError && e.status ? e.message : t("err.network")); }
    finally { setBusy(false); }
  }

  const lines = Object.entries(cart);
  const modNames = (it: CatalogItem | undefined, mods: string[]) => it?.modifierGroups.flatMap((g) => g.modifiers).filter((m) => mods.includes(m.id)).map((m) => m.name).join(", ");

  return (
    <Shell>
      <main className="space-y-4">
        <div className="flex items-center justify-between"><Link href="/waiter" className="underline">← {t("back")}</Link><h1 className="text-lg font-bold">{table?.label}</h1></div>
        {err && <p role="alert" className="text-sm text-red-700">{err}</p>}
        {msg && <p role="status" className="text-sm text-green-700">{msg}</p>}

        {order && (
          <section className="card">
            <h2 className="mb-1 font-semibold">{t("t.current")} · #{order.code}</h2>
            <ul className="text-sm">{order.items.map((i) => <li key={i.id} className="flex justify-between"><span>{i.quantity}× {i.name}</span><span className="text-soft">{i.kitchenState}</span></li>)}</ul>
            <p className="mt-1 text-sm">{t("t.total")}: <b>{money(order.grandTotal, locale)}</b></p>
          </section>
        )}

        {picking ? (
          <section className="card space-y-3">
            <h2 className="font-semibold">{picking.name}</h2>
            {picking.modifierGroups.map((g) => (
              <fieldset key={g.id}><legend className="text-sm font-medium">{g.name}{g.isRequired ? " *" : ""}</legend>
                {g.modifiers.map((m) => (
                  <label key={m.id} className="flex min-h-[44px] items-center gap-3"><input type={g.maxSelect === 1 ? "radio" : "checkbox"} name={g.id} className="h-5 w-5" checked={picked.includes(m.id)}
                    onChange={(e) => setPicked((p) => g.maxSelect === 1 ? [...p.filter((x) => !g.modifiers.some((y) => y.id === x)), m.id] : e.target.checked ? [...p, m.id] : p.filter((x) => x !== m.id))} />
                    {m.name}{m.price ? ` (+${money(m.price, locale)})` : ""}</label>
                ))}
              </fieldset>
            ))}
            <input className="field" placeholder={t("t.note")} value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} />
            {!groupsOk(picking.modifierGroups, picked) && <p className="text-sm text-amber-700">{t("t.chooseOptions")}</p>}
            <button className="btn" disabled={!groupsOk(picking.modifierGroups, picked)} onClick={() => addLine(picking, picked, note)}>{t("t.add")}</button>
            <button className="btn-ghost" onClick={() => setPicking(null)}>{t("back")}</button>
          </section>
        ) : (
          <section>
            <div className="mb-2 flex gap-2 overflow-x-auto pb-1" role="tablist">{cat?.categories.map((c) => (
              <button key={c.id} role="tab" aria-selected={category === c.id} onClick={() => setCategory(c.id)} className={`min-h-[44px] shrink-0 rounded-xl px-4 text-sm font-semibold ${category === c.id ? "bg-brand text-white" : "border border-line bg-white"}`}>{c.name}</button>
            ))}</div>
            <ul className="space-y-2">{items.map((i) => (
              <li key={i.id}><button className="card flex w-full items-center justify-between text-left active:bg-black/5" onClick={() => startAdd(i)}><span>{i.name}</span><span className="text-sm text-soft">{money(i.basePrice, locale)} +</span></button></li>
            ))}</ul>
          </section>
        )}

        <section className="card space-y-2">
          <h2 className="font-semibold">{t("t.cart")}</h2>
          {lines.length === 0 ? <p className="text-sm text-soft">{t("t.empty")}</p> : (<>
            <ul className="space-y-2 text-sm">{lines.map(([k, l]) => {
              const it = cat?.items.find((x) => x.id === l.itemId);
              return (
                <li key={k} className="flex items-center justify-between gap-2">
                  <span>{it?.name}{modNames(it, l.modifierIds) ? ` (${modNames(it, l.modifierIds)})` : ""}{l.notes ? <i className="block text-xs text-soft">{l.notes}</i> : null}</span>
                  <span className="flex items-center gap-2"><button className="btn-ghost !min-h-[44px] !w-11 !px-0" aria-label="-" onClick={() => bump(k, -1)}>−</button><b>{l.quantity}</b><button className="btn-ghost !min-h-[44px] !w-11 !px-0" aria-label="+" onClick={() => bump(k, 1)}>+</button></span>
                </li>
              );
            })}</ul>
            <p className="text-xs text-soft">{t("t.estimate")}: {money(cartTotal(cart, priceOf), locale)}</p>
            <button className="btn" disabled={busy} onClick={send}>{busy ? t("loading") : t("t.send")}</button>
          </>)}
        </section>
      </main>
    </Shell>
  );
}
