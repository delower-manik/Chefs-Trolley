"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { api, ApiError } from "@/lib/client-api";
import type { Branch, Reservation } from "@/lib/types";
import { useApp } from "@/components/Providers";
import Shell from "@/components/Shell";

export default function Reservations() {
  const { t, customer, ready, lang } = useApp();
  const [branches, setBranches] = useState<Branch[]>([]);
  const [mine, setMine] = useState<Reservation[]>([]);
  const [f, setF] = useState({ branch: "", party: 2, when: "", notes: "" });
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const load = useCallback(() => api<Reservation[]>("/web/reservations").then(setMine).catch(() => setMine([])), []);
  useEffect(() => { api<Branch[]>("/web/branches").then((b) => { setBranches(b); setF((x) => ({ ...x, branch: x.branch || b[0]?.id || "" })); }).catch(() => undefined); }, []);
  useEffect(() => { if (customer) void load(); }, [customer, load]);

  async function book(e: React.FormEvent) {
    e.preventDefault(); setBusy(true); setMsg(null);
    try { await api("/web/reservations", { body: { branch_id: f.branch, party_size: f.party, reserved_for: new Date(f.when).toISOString(), notes: f.notes || undefined } }); setMsg(t("r.booked")); setF({ ...f, when: "", notes: "" }); await load(); }
    catch (x) { setMsg(x instanceof ApiError ? x.message : t("err.network")); } finally { setBusy(false); }
  }
  async function cancel(id: string) { try { await api(`/web/reservations/${id}/cancel`, { method: "POST", body: {} }); await load(); } catch (x) { setMsg(x instanceof ApiError ? x.message : t("err.network")); } }

  if (ready && !customer) return <Shell><div className="card text-center"><p>{t("home.signin")}</p><Link href="/login?next=/reservations" className="btn mt-4">{t("login.send")}</Link></div></Shell>;
  return (
    <Shell>
      <main className="space-y-4">
        <form onSubmit={book} className="card space-y-3"><h1 className="text-lg font-bold">{t("r.title")}</h1>
          {branches.length > 1 && <div><label className="label" htmlFor="b">{t("r.branch")}</label><select id="b" className="field" value={f.branch} onChange={(e) => setF({ ...f, branch: e.target.value })}>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</select></div>}
          <div><label className="label" htmlFor="p">{t("r.party")}</label><input id="p" type="number" min={1} max={30} className="field" value={f.party} onChange={(e) => setF({ ...f, party: Number(e.target.value) })} required /></div>
          <div><label className="label" htmlFor="w">{t("r.when")}</label><input id="w" type="datetime-local" className="field" value={f.when} onChange={(e) => setF({ ...f, when: e.target.value })} required /></div>
          <div><label className="label" htmlFor="nt">{t("r.notes")}</label><input id="nt" className="field" maxLength={200} value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} /></div>
          {msg && <p role="status" className="text-sm">{msg}</p>}
          <button className="btn" disabled={busy}>{t("r.book")}</button></form>
        <section className="space-y-2"><h2 className="font-semibold">{t("r.mine")}</h2>
          {mine.length === 0 && <p className="text-sm text-soft">{t("r.none")}</p>}
          {mine.map((r) => <div key={r.id} className="card flex items-center justify-between gap-2 text-sm"><span>{new Date(r.reservedFor).toLocaleString(lang === "bn" ? "bn-BD" : "en-BD")} · {r.partySize}<br /><i className="text-soft">{r.status}</i></span>
            {["pending", "confirmed"].includes(r.status) && <button className="btn-ghost !w-auto" onClick={() => cancel(r.id)}>{t("r.cancel")}</button>}</div>)}
        </section>
      </main>
    </Shell>
  );
}
