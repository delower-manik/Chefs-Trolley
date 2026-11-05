"use client";

import { useEffect, useState } from "react";
import { api, ApiError } from "@/lib/client-api";
import { useApp } from "@/components/providers";
import RequireLogin from "@/components/RequireLogin";
import type { Branch, Reservation } from "@/lib/types";
import { API } from "@/lib/config";

function Reserve() {
  const { t, site } = useApp();
  const [branches, setBranches] = useState<Branch[]>([]);
  const [mine, setMine] = useState<Reservation[]>([]);
  const [f, setF] = useState({ branch: "", party: 2, when: "", notes: "" });
  const [msg, setMsg] = useState<string | null>(null);
  const load = () => api<Reservation[]>("/web/reservations").then(setMine).catch(() => setMine([]));
  useEffect(() => {
    load();
    fetch(`${API}/web/branches`).then((r) => r.json()).then((j) => { setBranches(j.data ?? []); setF((x) => ({ ...x, branch: j.data?.[0]?.id ?? "" })); }).catch(() => setBranches([]));
  }, []);
  if (!site.features.reservations) return <p className="card">{t("common.error")}</p>;

  async function send(e: React.FormEvent) {
    e.preventDefault(); setMsg(null);
    try {
      await api("/web/reservations", { body: { branch_id: f.branch, party_size: f.party, reserved_for: new Date(f.when).toISOString(), notes: f.notes || undefined } });
      setMsg(t("res.note")); setF({ ...f, when: "", notes: "" }); load();
    } catch (x) { const a = x as ApiError; setMsg(a.fieldErrors ? Object.values(a.fieldErrors).flat()[0] ?? a.message : a.message); }
  }
  async function cancel(id: string) { try { await api(`/web/reservations/${id}/cancel`, { method: "POST", body: {} }); load(); } catch (x) { setMsg((x as ApiError).message); } }

  return (
    <div className="mx-auto max-w-xl space-y-4">
      <h1 className="text-3xl font-bold">{t("res.title")}</h1>
      <form onSubmit={send} className="card space-y-3">
        {branches.length > 1 && <div><label className="label" htmlFor="b">{t("menu.branch")}</label><select id="b" className="field" value={f.branch} onChange={(e) => setF({ ...f, branch: e.target.value })}>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</select></div>}
        <div><label className="label" htmlFor="g">{t("res.party")}</label><input id="g" type="number" min={1} max={30} className="field" value={f.party} onChange={(e) => setF({ ...f, party: Number(e.target.value) })} /></div>
        <div><label className="label" htmlFor="w">{t("res.when")}</label><input id="w" type="datetime-local" required className="field" value={f.when} onChange={(e) => setF({ ...f, when: e.target.value })} /></div>
        <div><label className="label" htmlFor="nt">{t("res.notes")}</label><input id="nt" className="field" maxLength={300} value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} /></div>
        <button className="btn" disabled={!f.branch}>{t("res.send")}</button>
        {msg && <p role="status" className="text-sm">{msg}</p>}
      </form>
      {mine.length > 0 && (
        <section><h2 className="mb-2 font-semibold">{t("res.mine")}</h2>
          <ul className="space-y-2">{mine.map((r) => (<li key={r.id} className="card flex items-center justify-between gap-2 text-sm"><span>{new Date(r.reservedFor).toLocaleString()} · {r.partySize} · {t(`status.${r.status}` as never)}</span>{(r.status === "pending" || r.status === "confirmed") && <button className="btn-ghost !min-h-[36px]" onClick={() => cancel(r.id)}>{t("common.cancel")}</button>}</li>))}</ul></section>
      )}
    </div>
  );
}
export default function ReservationsPage() { return (<RequireLogin next="/reservations"><Reserve /></RequireLogin>); }
