"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { ageTone, minutesAgo } from "@/lib/format";
import type { Station, Ticket } from "@/lib/types";
import { useApp } from "@/components/Providers";
import Shell from "@/components/Shell";

const NEXT: Record<string, "k.next.new" | "k.next.accepted" | "k.next.preparing"> = { new: "k.next.new", accepted: "k.next.accepted", preparing: "k.next.preparing" };
const TONE = { ok: "border-line", warn: "border-amber-400", late: "border-red-500" } as const;

export default function Kitchen() {
  const { t, can } = useApp();
  const [tickets, setTickets] = useState<Ticket[]>([]);
  const [stations, setStations] = useState<Station[]>([]);
  const [station, setStation] = useState("");
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const load = useCallback(async () => {
    try { setErr(null); setTickets(await api<Ticket[]>(`/kitchen/tickets${station ? `?station_id=${station}` : ""}`)); }
    catch (e) { setErr(e instanceof ApiError && e.status ? e.message : t("err.network")); }
  }, [station, t]);
  useEffect(() => { if (can("kds.access")) api<Station[]>("/kitchen/stations").then(setStations).catch(() => undefined); }, [can]);
  useEffect(() => { void load(); }, [load]);
  useEffect(() => {
    const v = () => { if (document.visibilityState === "visible") void load(); };
    document.addEventListener("visibilitychange", v);
    const id = setInterval(v, 10000);
    return () => { document.removeEventListener("visibilitychange", v); clearInterval(id); };
  }, [load]);

  async function move(tk: Ticket, dir: "bump" | "reopen") {
    setBusy(tk.id);
    try { await api(`/kitchen/tickets/${tk.id}/${dir}${station ? `?station_id=${station}` : ""}`, { body: {} }); await load(); }
    catch (e) { setErr(e instanceof ApiError && e.status ? e.message : t("err.network")); }
    finally { setBusy(null); }
  }

  return (
    <Shell>
      <main className="space-y-3">
        <select className="field" value={station} onChange={(e) => setStation(e.target.value)} aria-label={t("k.all")}>
          <option value="">{t("k.all")}</option>
          {stations.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
        {err && <p role="alert" className="text-sm text-red-700">{err}</p>}
        {tickets.length === 0 && !err && <p className="text-soft">{t("k.empty")}</p>}
        {tickets.map((tk) => {
          const min = minutesAgo(tk.createdAt);
          return (
            <article key={tk.id} className={`card border-2 ${TONE[ageTone(min)]}`}>
              <div className="flex justify-between"><b>#{tk.code}{tk.tableLabel ? ` · ${t("k.table", { t: tk.tableLabel })}` : ""}</b><span className="text-sm">{t("k.min", { n: min ?? 0 })}</span></div>
              <p className="text-xs text-soft">{t(`k.state.${tk.kitchenState}` as "k.state.new")}</p>
              <ul className="my-2 text-sm">{tk.items.map((i) => (
                <li key={i.id}><b>{i.quantity}×</b> {i.name}{i.stationName && !station ? ` [${i.stationName}]` : ""}{i.notes ? <span className="block text-amber-700">⚠ {i.notes}</span> : null}</li>
              ))}</ul>
              <div className="grid grid-cols-3 gap-2">
                {NEXT[tk.kitchenState] ? <button className="btn col-span-2" disabled={busy === tk.id} onClick={() => move(tk, "bump")}>{t(NEXT[tk.kitchenState])}</button> : <span className="col-span-2" />}
                {tk.kitchenState !== "new" && <button className="btn-ghost" disabled={busy === tk.id} onClick={() => move(tk, "reopen")}>{t("k.undo")}</button>}
              </div>
            </article>
          );
        })}
      </main>
    </Shell>
  );
}
