"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/client-api";
import type { Branch, Menu, MenuItem } from "@/lib/types";
import { useApp } from "@/components/Providers";
import Shell from "@/components/Shell";

const groupsOk = (groups: MenuItem["modifierGroups"], picked: string[]) => groups.every((g) => {
  const n = g.modifiers.filter((m) => picked.includes(m.id)).length;
  return n >= (g.required ? Math.max(1, g.min) : g.min) && (g.max === 0 || n <= g.max);
});

export default function MenuPage() {
  const { t, money, site, addLine, cart } = useApp();
  const [branches, setBranches] = useState<Branch[]>([]);
  const [branchId, setBranchId] = useState("");
  const [menu, setMenu] = useState<Menu | null>(null);
  const [cat, setCat] = useState("");
  const [err, setErr] = useState<string | null>(null);
  const [pick, setPick] = useState<MenuItem | null>(null);
  const [mods, setMods] = useState<string[]>([]);
  const [note, setNote] = useState("");
  const [msg, setMsg] = useState<string | null>(null);

  useEffect(() => { api<Branch[]>("/web/branches").then((b) => { setBranches(b); setBranchId((cur) => cur || cart.branchId || b[0]?.id || ""); }).catch(() => setErr(t("err.network"))); }, []); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (!branchId) return;
    api<Menu>(`/web/branches/${branchId}/menu`).then((m) => { setMenu(m); setCat((c) => c || m.categories[0]?.id || ""); setErr(null); }).catch(() => setErr(t("err.network")));
  }, [branchId, t]);

  const orderable = !!site?.ordering.enabled;
  function add(item: MenuItem, modIds: string[], n: string) {
    const names = item.modifierGroups.flatMap((g) => g.modifiers).filter((m) => modIds.includes(m.id));
    const unit = item.price + names.reduce((s, m) => s + m.price, 0);
    const switched = addLine(branchId, { itemId: item.id, name: item.name, unitPrice: unit, quantity: 1, modifierIds: modIds, modifierNames: names.map((m) => m.name), notes: n.trim() || undefined });
    setMsg(switched ? t("menu.switched") : null); setPick(null);
  }
  const start = (i: MenuItem) => (i.modifierGroups.length ? (setPick(i), setMods([]), setNote("")) : add(i, [], ""));
  const category = menu?.categories.find((c) => c.id === cat);

  return (
    <Shell>
      <main className="space-y-3">
        {branches.length > 1 && <select className="field" value={branchId} onChange={(e) => { setBranchId(e.target.value); setCat(""); }}>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</select>}
        {err && <p role="alert" className="text-sm text-red-700">{err}</p>}
        {msg && <p role="status" className="text-sm text-amber-700">{msg}</p>}
        {pick ? (
          <section className="card space-y-3">
            <h2 className="font-semibold">{pick.name} · {money(pick.price)}</h2>
            {pick.modifierGroups.map((g) => (
              <fieldset key={g.id}><legend className="text-sm font-medium">{g.name}{g.required ? ` · ${t("menu.required")}` : ""}</legend>
                {g.modifiers.map((m) => (
                  <label key={m.id} className="flex min-h-[44px] items-center gap-3"><input type={g.max === 1 ? "radio" : "checkbox"} name={g.id} className="h-5 w-5" checked={mods.includes(m.id)}
                    onChange={(e) => setMods((p) => g.max === 1 ? [...p.filter((x) => !g.modifiers.some((y) => y.id === x)), m.id] : e.target.checked ? [...p, m.id] : p.filter((x) => x !== m.id))} />
                    {m.name}{m.price ? ` (+${money(m.price)})` : ""}</label>
                ))}
              </fieldset>
            ))}
            <input className="field" placeholder={t("menu.note")} value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} />
            {!groupsOk(pick.modifierGroups, mods) && <p className="text-sm text-amber-700">{t("menu.chooseRequired")}</p>}
            <button className="btn" disabled={!groupsOk(pick.modifierGroups, mods)} onClick={() => add(pick, mods, note)}>{t("menu.add")}</button>
            <button className="btn-ghost" onClick={() => setPick(null)}>{t("close")}</button>
          </section>
        ) : (<>
          <div className="flex gap-2 overflow-x-auto pb-1" role="tablist">
            {menu?.categories.map((c) => <button key={c.id} role="tab" aria-selected={cat === c.id} onClick={() => setCat(c.id)} className={`min-h-[44px] shrink-0 rounded-xl px-4 text-sm font-semibold ${cat === c.id ? "bg-brand text-white" : "border border-line bg-white"}`}>{c.name}</button>)}
            {menu && menu.combos.length > 0 && <button role="tab" aria-selected={cat === "combos"} onClick={() => setCat("combos")} className={`min-h-[44px] shrink-0 rounded-xl px-4 text-sm font-semibold ${cat === "combos" ? "bg-brand text-white" : "border border-line bg-white"}`}>{t("menu.combos")}</button>}
          </div>
          {!menu && !err && <p className="text-soft">{t("loading")}</p>}
          <ul className="space-y-2">
            {cat === "combos"
              ? menu?.combos.map((c) => (
                <li key={c.id} className="card"><div className="flex items-center justify-between gap-2"><div><b>{c.name}</b><p className="text-xs text-soft">{c.includes.map((i) => `${i.quantity}× ${i.name}`).join(", ")}</p></div>
                  <button className="btn !w-auto" disabled={!orderable} onClick={() => { const sw = addLine(branchId, { comboId: c.id, name: c.name, unitPrice: c.price, quantity: 1, modifierIds: [], modifierNames: [] }); setMsg(sw ? t("menu.switched") : null); }}>{money(c.price)} +</button></div></li>))
              : category?.items.map((i) => (
                <li key={i.id} className="card"><div className="flex items-center justify-between gap-2"><div><b>{i.name}</b>{i.description && <p className="text-xs text-soft">{i.description}</p>}</div>
                  <button className="btn !w-auto" disabled={!orderable} onClick={() => start(i)}>{money(i.price)} +</button></div></li>))}
          </ul>
        </>)}
      </main>
    </Shell>
  );
}
