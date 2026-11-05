"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { api, ApiError } from "@/lib/api";
import { money, minutesAgo } from "@/lib/format";
import { enablePush, pushStatus, type PushState } from "@/lib/push";
import { useLocationShare } from "@/lib/use-location-share";
import type { Cash, Delivery } from "@/lib/types";
import { useApp } from "@/components/Providers";

type Tab = "active" | "history" | "cash";

export default function Home() {
  const { t, lang, setLang, locale, profile, reload, signOut, online } = useApp();
  const [tab, setTab] = useState<Tab>("active");
  const [rows, setRows] = useState<Delivery[]>([]);
  const [cash, setCash] = useState<Cash | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const [push, setPush] = useState<PushState>("off");

  const load = useCallback(async () => {
    try {
      setErr(null);
      if (tab === "cash") setCash(await api<Cash>("/rider/cash"));
      else setRows(await api<Delivery[]>(`/rider/deliveries?scope=${tab}`));
      await reload();
    } catch (e) { setErr(e instanceof ApiError && e.status ? e.message : t("err.network")); }
  }, [tab, reload, t]);

  useEffect(() => { void load(); }, [load]);
  useEffect(() => { setPush(pushStatus()); }, []);
  useEffect(() => { // refresh when the rider comes back to the app, and every 30s while it is open
    const v = () => { if (document.visibilityState === "visible") void load(); };
    document.addEventListener("visibilitychange", v);
    const id = setInterval(v, 30000);
    return () => { document.removeEventListener("visibilitychange", v); clearInterval(id); };
  }, [load]);

  const sharing = !!profile?.locationSharing;
  const geo = useLocationShare(sharing, () => { void api("/rider/sharing", { body: { on: false } }).then(reload).catch(() => undefined); });

  async function toggleStatus() {
    if (!profile) return;
    try { await api("/rider/status", { method: "PUT", body: { status: profile.status === "offline" ? "available" : "offline" } }); await reload(); }
    catch (e) { setErr(e instanceof ApiError ? e.message : t("err.generic")); }
  }
  async function toggleShare() {
    try { await api("/rider/sharing", { body: { on: !sharing } }); await reload(); }
    catch (e) { setErr(e instanceof ApiError ? e.message : t("err.generic")); }
  }

  if (!profile) return <p>{t("loading")}</p>;
  const busy = profile.openDeliveries > 0;

  return (
    <main className="space-y-4">
      {!online && <p className="rounded-xl bg-amber-100 p-3 text-sm">{t("offline")}</p>}
      <header className="flex items-center justify-between">
        <div><h1 className="text-xl font-bold">{profile.name}</h1><p className="text-sm text-soft">{t(profile.status === "available" ? "status.available" : profile.status === "busy" ? "status.busy" : "status.offline")}</p></div>
        <div className="flex gap-3 text-sm underline">
          <button onClick={() => setLang(lang === "bn" ? "en" : "bn")}>{lang === "bn" ? "EN" : "বাং"}</button>
          <button onClick={() => void signOut()}>{t("signout")}</button>
        </div>
      </header>

      <section className="card space-y-3">
        <button className="btn-ghost" disabled={busy} onClick={toggleStatus}>{profile.status === "offline" ? t("status.goOnline") : t("status.goOffline")}</button>
        {busy && (<>
          <label className="flex items-start gap-3 text-sm"><input type="checkbox" className="mt-1 h-5 w-5" checked={sharing} onChange={toggleShare} /><span><b>{t("loc.title")}</b>{sharing && ` · ${t("loc.on")}`}<br /><span className="text-soft">{t("loc.note")}</span></span></label>
          {geo === "denied" && <p className="text-sm text-red-700">{t("loc.denied")}</p>}
          {geo === "unsupported" && <p className="text-sm text-red-700">{t("loc.unsupported")}</p>}
        </>)}
        {push === "off" && <button className="btn-ghost" onClick={async () => setPush(await enablePush(() => void load()).catch((): PushState => "off"))}>{t("push.enable")}</button>}
        {push === "on" && <p className="text-sm text-soft">{t("push.on")}</p>}
        {push === "blocked" && <p className="text-sm text-soft">{t("push.blocked")}</p>}
        {push === "unavailable" && <p className="text-xs text-soft">{t("push.unavailable")}</p>}
      </section>

      <nav className="grid grid-cols-3 gap-2" role="tablist">
        {(["active", "history", "cash"] as Tab[]).map((k) => (
          <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)} className={`min-h-[44px] rounded-xl text-sm font-semibold ${tab === k ? "bg-brand text-white" : "bg-white border border-line"}`}>{t(`tab.${k}` as const)}</button>
        ))}
      </nav>
      <button className="text-sm underline" onClick={() => void load()}>{t("list.refresh")}</button>
      {err && <p role="alert" className="text-sm text-red-700">{err}</p>}

      {tab === "cash" ? (
        <section className="card">
          {cash && cash.holding > 0 ? (<>
            <p className="text-sm text-soft">{t("cash.holding")}</p><p className="text-3xl font-bold">{money(cash.holding, locale)}</p>
            <ul className="mt-3 divide-y divide-line text-sm">{cash.items.map((i, n) => <li key={n} className="flex justify-between py-2"><span>#{i.orderCode}</span><span>{money(i.amount, locale)}</span></li>)}</ul>
            <p className="mt-3 text-sm text-soft">{t("cash.note")}</p>
          </>) : <p>{t("cash.none")}</p>}
        </section>
      ) : rows.length === 0 ? <p className="text-soft">{t(tab === "active" ? "list.empty" : "list.emptyHistory")}</p> : (
        <ul className="space-y-3">{rows.map((d) => (
          <li key={d.id}><Link href={`/d/${d.id}`} className="card block active:bg-black/5">
            <div className="flex justify-between"><b>#{d.orderCode}</b><span className="text-sm">{d.status === "assigned" ? t("d.pickup") : d.status === "picked_up" ? t("status.busy") : d.status === "delivered" ? t("d.delivered") : t("d.failed")}</span></div>
            <p className="mt-1 text-sm">{d.address}</p>
            {tab === "active" && d.cashToCollect > 0 && <p className="mt-1 font-semibold">{t("d.collect")}: {money(d.cashToCollect, locale)}</p>}
            {d.cancelled && <p className="text-sm text-red-700">{t("list.cancelledNote")}</p>}
            {tab === "active" && d.assignedAt && <p className="text-xs text-soft">{t("min.ago", { n: minutesAgo(d.assignedAt) ?? 0 })}</p>}
          </Link></li>
        ))}</ul>
      )}
    </main>
  );
}
