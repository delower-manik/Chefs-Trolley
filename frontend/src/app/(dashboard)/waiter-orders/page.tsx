"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { ApiError } from "@/lib/api/client";
import { createOrder, useOrder } from "@/hooks/use-orders";
import { addOrderItems, transferOrderTable, usePosCatalog } from "@/hooks/use-restaurant";
import { formatBDT } from "@/lib/format";
import type { CatalogItem, CatalogTable, OrderLineInput } from "@/types/restaurant";

type Line = { key: string; name: string; unit: number; input: OrderLineInput };

export default function WaiterOrdersPage() {
  const router = useRouter();
  const { catalog, isLoading, error: loadError, refresh } = usePosCatalog();
  const [planId, setPlanId] = useState("");
  const [tableId, setTableId] = useState("");
  const [categoryId, setCategoryId] = useState("");
  const [round, setRound] = useState<Line[]>([]);
  const [picker, setPicker] = useState<CatalogItem | null>(null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);

  const tables = catalog?.tables ?? [];
  const visibleTables = tables.filter((t) => !planId || t.floorPlanId === planId);
  const table: CatalogTable | undefined = tables.find((t) => t.id === tableId);
  const { order } = useOrderSafe(table?.openOrder?.id);
  const items = (catalog?.items ?? []).filter((i) => !categoryId || i.categoryId === categoryId);
  const roundTotal = round.reduce((s, l) => s + l.unit * l.input.quantity, 0);

  function add(key: string, name: string, unit: number, input: OrderLineInput) {
    setRound((prev) => {
      const found = prev.find((l) => l.key === key);
      if (found) return prev.map((l) => (l.key === key ? { ...l, input: { ...l.input, quantity: l.input.quantity + 1 } } : l));
      return [...prev, { key, name, unit, input }];
    });
  }

  function changeQty(key: string, d: number) {
    setRound((prev) => prev.flatMap((l) => (l.key !== key ? [l] : l.input.quantity + d <= 0 ? [] : [{ ...l, input: { ...l.input, quantity: l.input.quantity + d } }])));
  }

  async function send() {
    if (!table || round.length === 0) return;
    setBusy(true);
    setMsg(null);
    try {
      const lines = round.map((l) => l.input);
      if (table.openOrder) await addOrderItems(table.openOrder.id, lines);
      else await createOrder({ source: "dine_in", dining_table_id: table.id, items: lines });
      setRound([]);
      await refresh();
      setMsg("Round sent to the kitchen.");
    } catch (err) {
      setMsg(err instanceof ApiError ? err.message : "Couldn't send this round.");
    } finally {
      setBusy(false);
    }
  }

  async function transfer(toId: string) {
    if (!table?.openOrder || !toId) return;
    try {
      await transferOrderTable(table.openOrder.id, toId);
      setTableId(toId);
      await refresh();
      setMsg("Tab moved.");
    } catch (err) {
      setMsg(err instanceof ApiError ? err.message : "Couldn't move the tab.");
    }
  }

  const free = useMemo(() => tables.filter((t) => !t.openOrder && t.status !== "out_of_service"), [tables]);

  if (loadError) return <div className="card p-10 text-center text-sm text-text-secondary">Couldn&apos;t load tables and menu.</div>;

  return (
    <section className="space-y-4">
      <div>
        <h2 className="text-2xl font-bold text-strong">Waiter Orders</h2>
        <p className="text-sm text-text-secondary">Pick a table, add dishes in rounds. Each round goes straight to the kitchen and joins the table&apos;s running tab.</p>
      </div>

      <div className="grid lg:grid-cols-[320px_1fr_340px] gap-4">
        {/* Tables */}
        <div className="card p-3 space-y-3">
          {(catalog?.floorPlans.length ?? 0) > 0 && (
            <select value={planId} onChange={(e) => setPlanId(e.target.value)} className="input-field text-sm">
              <option value="">All areas</option>
              {catalog?.floorPlans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          )}
          {isLoading ? <div className="h-24 bg-surface-hover rounded-card animate-pulse" /> : (
            <div className="grid grid-cols-3 gap-2">
              {visibleTables.map((t) => (
                <button
                  key={t.id}
                  disabled={t.status === "out_of_service"}
                  onClick={() => { setTableId(t.id); setMsg(null); }}
                  className={`p-2 rounded-lg border text-center disabled:opacity-40 ${tableId === t.id ? "border-primary" : "border-border"} ${t.openOrder ? "bg-primary/10" : "bg-surface"}`}
                >
                  <div className="font-bold text-strong text-sm">{t.label}</div>
                  <div className="text-[10px] text-text-secondary">{t.openOrder ? formatBDT(t.openOrder.grandTotal) : `${t.capacity} seats`}</div>
                </button>
              ))}
              {visibleTables.length === 0 && <div className="col-span-3 text-xs text-text-muted text-center py-4">No tables here.</div>}
            </div>
          )}
        </div>

        {/* Menu */}
        <div className="card p-3 space-y-3">
          <div className="flex gap-2 overflow-x-auto">
            {[{ id: "", name: "All" }, ...(catalog?.categories ?? [])].map((c) => (
              <button key={c.id || "all"} onClick={() => setCategoryId(c.id)} className={`px-3 py-1.5 rounded-full text-sm whitespace-nowrap ${categoryId === c.id ? "bg-primary text-on-primary" : "bg-surface-hover text-text-secondary"}`}>{c.name}</button>
            ))}
          </div>
          <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
            {!categoryId && catalog?.combos.map((c) => (
              <button key={c.id} disabled={!table} onClick={() => add(`combo::${c.id}`, c.name, c.price, { combo_id: c.id, quantity: 1 })} className="card p-2 text-left border-primary/40 disabled:opacity-40">
                <div className="text-[10px] text-primary-fg font-semibold uppercase">Combo</div>
                <div className="text-sm text-strong font-medium">{c.name}</div>
                <div className="text-xs text-text-secondary">{formatBDT(c.price)}</div>
              </button>
            ))}
            {items.map((i) => (
              <button
                key={i.id}
                disabled={!table}
                onClick={() => (i.modifierGroups.length > 0 ? setPicker(i) : add(i.id, i.name, i.basePrice, { food_item_id: i.id, quantity: 1 }))}
                className="card p-2 text-left disabled:opacity-40 hover:border-primary"
              >
                <div className="text-sm text-strong font-medium">{i.name}</div>
                <div className="text-xs text-text-secondary">{formatBDT(i.basePrice)}</div>
              </button>
            ))}
          </div>
          {!table && <p className="text-xs text-text-muted">Choose a table first.</p>}
        </div>

        {/* Tab + round */}
        <div className="card p-3 space-y-3">
          <h3 className="font-bold text-strong">{table ? `Table ${table.label}` : "No table selected"}</h3>
          {table?.openOrder && (
            <div className="text-sm space-y-1 border-b border-border pb-3">
              <div className="flex justify-between text-text-secondary">
                <span>Open tab {table.openOrder.code}</span>
                <button className="text-primary-fg text-xs" onClick={() => router.push(`/orders/${table.openOrder!.id}`)}>View / pay</button>
              </div>
              {order?.items.map((it) => (
                <div key={it.id} className="flex justify-between text-strong"><span>{it.quantity}× {it.name}</span><span>{formatBDT(it.total)}</span></div>
              ))}
              <div className="flex justify-between font-bold text-strong pt-1"><span>Tab total</span><span>{formatBDT(table.openOrder.grandTotal)}</span></div>
              <select className="input-field text-xs mt-2" value="" onChange={(e) => void transfer(e.target.value)}>
                <option value="">Move tab to table…</option>
                {free.map((t) => <option key={t.id} value={t.id}>{t.label}</option>)}
              </select>
            </div>
          )}
          <div className="space-y-2">
            <div className="text-xs font-medium text-text-secondary">New round</div>
            {round.length === 0 ? <div className="text-xs text-text-muted">Tap dishes to add them.</div> : round.map((l) => (
              <div key={l.key} className="flex items-center justify-between text-sm">
                <span className="text-strong">{l.name}</span>
                <span className="flex items-center gap-2">
                  <button onClick={() => changeQty(l.key, -1)} className="w-6 h-6 rounded bg-surface-hover text-strong">−</button>
                  <span className="w-4 text-center text-strong">{l.input.quantity}</span>
                  <button onClick={() => changeQty(l.key, 1)} className="w-6 h-6 rounded bg-surface-hover text-strong">+</button>
                </span>
              </div>
            ))}
          </div>
          {msg && <div className="text-xs text-text-secondary">{msg}</div>}
          <div className="flex justify-between font-bold text-strong"><span>Round subtotal</span><span>{formatBDT(roundTotal)}</span></div>
          <p className="text-[11px] text-text-muted">Tax is calculated by the server when the round is sent.</p>
          <button className="btn-primary w-full py-3" disabled={!table || round.length === 0 || busy} onClick={send}>
            {busy ? "Sending..." : table?.openOrder ? "Add round to tab" : "Open tab & send"}
          </button>
        </div>
      </div>

      {picker && <ModifierDialog item={picker} onClose={() => setPicker(null)} onConfirm={(ids, extra) => {
        add(`${picker.id}::${[...ids].sort().join(",")}`, picker.name, picker.basePrice + extra, { food_item_id: picker.id, quantity: 1, modifier_ids: ids });
        setPicker(null);
      }} />}
    </section>
  );
}

function useOrderSafe(id?: string) {
  // useOrder requires an id; only fetch when a tab is open.
  const res = useOrder(id ?? "");
  return { order: id ? res.order : undefined };
}

function ModifierDialog({ item, onClose, onConfirm }: { item: CatalogItem; onClose: () => void; onConfirm: (ids: string[], extra: number) => void }) {
  const [sel, setSel] = useState<Record<string, string[]>>({});
  const toggle = (gid: string, mid: string, max: number) =>
    setSel((p) => {
      const cur = p[gid] ?? [];
      if (cur.includes(mid)) return { ...p, [gid]: cur.filter((x) => x !== mid) };
      if (max === 1) return { ...p, [gid]: [mid] };
      return cur.length < max ? { ...p, [gid]: [...cur, mid] } : p;
    });
  const ok = item.modifierGroups.filter((g) => g.isRequired).every((g) => (sel[g.id]?.length ?? 0) >= Math.max(1, g.minSelect));
  const ids = Object.values(sel).flat();
  const extra = item.modifierGroups.flatMap((g) => g.modifiers).filter((m) => ids.includes(m.id)).reduce((s, m) => s + m.price, 0);
  return (
    <div className="fixed inset-0 bg-black/60 grid place-items-center z-50 p-4" onClick={onClose}>
      <div className="card p-5 w-full max-w-md max-h-[80vh] overflow-y-auto" onClick={(e) => e.stopPropagation()}>
        <h3 className="font-bold text-strong mb-3">{item.name}</h3>
        {item.modifierGroups.map((g) => (
          <div key={g.id} className="mb-3">
            <div className="text-sm font-semibold text-strong mb-1">{g.name} {g.isRequired && <span className="text-danger">*</span>}</div>
            {g.modifiers.map((m) => (
              <button key={m.id} onClick={() => toggle(g.id, m.id, g.maxSelect)} className={`w-full flex justify-between px-3 py-2 mb-1 rounded-lg text-sm border ${(sel[g.id] ?? []).includes(m.id) ? "border-primary bg-primary/10 text-strong" : "border-border text-text-secondary"}`}>
                <span>{m.name}</span><span>{m.price > 0 ? `+${formatBDT(m.price)}` : "Free"}</span>
              </button>
            ))}
          </div>
        ))}
        <div className="flex gap-2 justify-end">
          <button onClick={onClose} className="btn-secondary">Cancel</button>
          <button disabled={!ok} onClick={() => onConfirm(ids, extra)} className="btn-primary">Add</button>
        </div>
      </div>
    </div>
  );
}
