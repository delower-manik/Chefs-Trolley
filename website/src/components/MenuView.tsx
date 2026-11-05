"use client";

import { useState } from "react";
import L from "./L";
import { useApp } from "./providers";
import type { Menu, MenuItem } from "@/lib/types";

export default function MenuView({ menu }: { menu: Menu }) {
  const { t, money, addLine, site } = useApp();
  const [open, setOpen] = useState<MenuItem | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const canOrder = site.ordering.enabled;

  function added(name: string, switched: boolean) {
    setToast(switched ? `${name} — cart switched to this branch.` : `${name} ✓`);
    window.setTimeout(() => setToast(null), 2500);
  }
  const quickAdd = (i: MenuItem) => {
    if (i.modifierGroups.length) return setOpen(i);
    added(i.name, addLine(menu.branch.id, { itemId: i.id, name: i.name, unitPrice: i.price, quantity: 1, modifierIds: [], modifierNames: [] }));
  };

  return (
    <div>
      <h1 className="mb-1 text-3xl font-bold">{t("menu.title")}</h1>
      <p className="mb-6 text-sm text-soft">{menu.branch.name}</p>
      {menu.categories.map((c) => (
        <section key={c.id} className="mb-8" aria-labelledby={`c-${c.id}`}>
          <h2 id={`c-${c.id}`} className="mb-3 text-xl font-bold">{c.name}</h2>
          <ul className="grid gap-3 sm:grid-cols-2">
            {c.items.map((i) => (
              <li key={i.id} className="card flex gap-3">
                {i.imageUrl && /* eslint-disable-next-line @next/next/no-img-element */ <img src={i.imageUrl} alt="" loading="lazy" className="h-20 w-20 shrink-0 rounded-xl object-cover" />}
                <div className="min-w-0 flex-1">
                  <p className="font-semibold"><L href={`/menu/${encodeURIComponent(i.slug)}?b=${menu.branch.id}`} className="hover:text-brand">{i.name}</L></p>
                  {i.description && <p className="line-clamp-2 text-sm text-soft">{i.description}</p>}
                  <div className="mt-2 flex items-center justify-between gap-2">
                    <span className="font-semibold">{money(i.price)}</span>
                    {canOrder && <button type="button" className="btn !min-h-[40px] !px-4" onClick={() => quickAdd(i)}>{t("menu.add")}</button>}
                  </div>
                </div>
              </li>
            ))}
          </ul>
        </section>
      ))}
      {menu.combos.length > 0 && (
        <section className="mb-8">
          <h2 className="mb-3 text-xl font-bold">{t("menu.combos")}</h2>
          <ul className="grid gap-3 sm:grid-cols-2">
            {menu.combos.map((c) => (
              <li key={c.id} className="card">
                <p className="font-semibold">{c.name}</p>
                {c.description && <p className="text-sm text-soft">{c.description}</p>}
                <p className="mt-1 text-xs text-soft">{t("menu.includes")}: {c.includes.map((x) => `${x.quantity}× ${x.name}`).join(", ")}</p>
                <div className="mt-2 flex items-center justify-between">
                  <span className="font-semibold">{money(c.price)}</span>
                  {canOrder && <button type="button" className="btn !min-h-[40px] !px-4" onClick={() => added(c.name, addLine(menu.branch.id, { comboId: c.id, name: c.name, unitPrice: c.price, quantity: 1, modifierIds: [], modifierNames: [] }))}>{t("menu.add")}</button>}
                </div>
              </li>
            ))}
          </ul>
        </section>
      )}
      {toast && <div role="status" className="fixed inset-x-4 bottom-4 z-40 mx-auto max-w-sm rounded-full bg-ink px-4 py-3 text-center text-sm text-white">{toast}</div>}
      {open && <ItemDialog item={open} onClose={() => setOpen(null)} onAdd={(l) => { added(open.name, addLine(menu.branch.id, l)); setOpen(null); }} />}
    </div>
  );
}

export function ItemDialog({ item, onClose, onAdd }: { item: MenuItem; onClose: () => void; onAdd: (l: Parameters<ReturnType<typeof useApp>["addLine"]>[1]) => void }) {
  const { t, money } = useApp();
  const [picked, setPicked] = useState<Record<string, string[]>>({});
  const [notes, setNotes] = useState("");
  const [qty, setQty] = useState(1);
  const toggle = (gid: string, mid: string, max: number) => setPicked((p) => {
    const cur = p[gid] ?? [];
    if (cur.includes(mid)) return { ...p, [gid]: cur.filter((x) => x !== mid) };
    return { ...p, [gid]: max === 1 ? [mid] : cur.length >= max ? cur : [...cur, mid] };
  });
  const missing = item.modifierGroups.some((g) => (picked[g.id]?.length ?? 0) < Math.max(g.min, g.required ? 1 : 0));
  const mods = item.modifierGroups.flatMap((g) => g.modifiers.filter((m) => picked[g.id]?.includes(m.id)));
  const unit = item.price + mods.reduce((s, m) => s + m.price, 0);
  return (
    <div role="dialog" aria-modal="true" aria-label={item.name} className="fixed inset-0 z-50 flex items-end justify-center bg-black/50 sm:items-center" onClick={onClose}>
      <div className="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-t-3xl bg-white p-5 sm:rounded-3xl" onClick={(e) => e.stopPropagation()}>
        <h2 className="text-xl font-bold">{item.name}</h2>
        {item.modifierGroups.map((g) => (
          <fieldset key={g.id} className="mt-4">
            <legend className="font-semibold">{g.name} {g.required && <span className="text-xs font-normal text-brand">({t("menu.required")})</span>}</legend>
            {g.modifiers.map((m) => (
              <label key={m.id} className="flex min-h-[44px] items-center gap-3">
                <input type={g.max === 1 ? "radio" : "checkbox"} name={g.id} checked={picked[g.id]?.includes(m.id) ?? false} onChange={() => toggle(g.id, m.id, g.max)} className="h-5 w-5 accent-brand" />
                <span className="flex-1">{m.name}</span>{m.price > 0 && <span className="text-sm text-soft">+{money(m.price)}</span>}
              </label>
            ))}
          </fieldset>
        ))}
        <label className="label mt-4" htmlFor="note">{t("menu.note")}</label>
        <input id="note" className="field" maxLength={255} value={notes} onChange={(e) => setNotes(e.target.value)} />
        <div className="mt-4 flex items-center gap-3">
          <button type="button" className="btn-ghost !px-4" aria-label="−" onClick={() => setQty(Math.max(1, qty - 1))}>−</button>
          <span className="w-6 text-center font-semibold">{qty}</span>
          <button type="button" className="btn-ghost !px-4" aria-label="+" onClick={() => setQty(Math.min(20, qty + 1))}>+</button>
          <button type="button" className="btn ml-auto" disabled={missing} onClick={() => onAdd({ itemId: item.id, name: item.name, unitPrice: unit, quantity: qty, modifierIds: mods.map((m) => m.id), modifierNames: mods.map((m) => m.name), notes: notes.trim() || undefined })}>
            {t("menu.addToCart")} · {money(unit * qty)}
          </button>
        </div>
        <button type="button" className="mt-3 w-full text-center text-sm text-soft" onClick={onClose}>{t("common.close")}</button>
      </div>
    </div>
  );
}
