"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { api, ApiError } from "@/lib/client-api";
import { enablePush, pushStatus, type PushState } from "@/lib/push";
import type { Address, Customer } from "@/lib/types";
import { useApp } from "@/components/Providers";
import Shell from "@/components/Shell";

type Loyalty = { points: number; history: { type: string; points: number; balance: number; reason: string | null; date: string }[] };

export default function Account() {
  const { t, customer, ready, refreshCustomer, signOut, lang, setLang, site } = useApp();
  const [f, setF] = useState({ name: "", email: "" });
  const [msg, setMsg] = useState<string | null>(null);
  const [loy, setLoy] = useState<Loyalty | null>(null);
  const [addrs, setAddrs] = useState<Address[]>([]);
  const [label, setLabel] = useState("");
  const [address, setAddress] = useState("");
  const [push, setPush] = useState<PushState>("off");

  const loadAddrs = useCallback(() => api<Address[]>("/web/addresses").then(setAddrs).catch(() => setAddrs([])), []);
  useEffect(() => { if (customer) { setF({ name: customer.name, email: customer.email ?? "" }); api<Loyalty>("/web/loyalty").then(setLoy).catch(() => setLoy(null)); void loadAddrs(); setPush(pushStatus()); } }, [customer, loadAddrs]);

  const say = (x: unknown) => setMsg(x instanceof ApiError ? x.message : t("err.network"));
  async function save(e: React.FormEvent) { e.preventDefault(); setMsg(null); try { await api<Customer>("/web/me", { method: "PUT", body: { name: f.name, email: f.email || null, locale: lang } }); await refreshCustomer(); setMsg(t("a.saved")); } catch (x) { say(x); } }
  async function addAddr(e: React.FormEvent) { e.preventDefault(); setMsg(null); try { await api("/web/addresses", { body: { label, address } }); setLabel(""); setAddress(""); await loadAddrs(); } catch (x) { say(x); } }
  const act = async (fn: () => Promise<unknown>) => { setMsg(null); try { await fn(); await loadAddrs(); } catch (x) { say(x); } };

  if (ready && !customer) return <Shell><div className="card text-center"><p>{t("home.signin")}</p><Link href="/login?next=/account" className="btn mt-4">{t("login.send")}</Link></div></Shell>;
  return (
    <Shell>
      <main className="space-y-4">
        <section className="card"><p className="text-sm text-soft">{t("a.points")}</p><p className="text-3xl font-bold text-brand">{loy?.points ?? customer?.loyaltyPoints ?? 0}</p>
          {loy && loy.history.length > 0 && <><p className="mt-2 text-xs text-soft">{t("a.history")}</p><ul className="divide-y divide-line text-sm">{loy.history.slice(0, 5).map((h, i) => <li key={i} className="flex justify-between py-1"><span>{h.reason ?? h.type}</span><b>{h.points > 0 ? "+" : ""}{h.points}</b></li>)}</ul></>}
        </section>
        <form onSubmit={save} className="card space-y-3">
          <div><label className="label" htmlFor="n">{t("a.name")}</label><input id="n" className="field" required value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></div>
          <div><label className="label" htmlFor="e">{t("a.email")}</label><input id="e" type="email" className="field" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} /></div>
          <button className="btn">{t("a.save")}</button>
          {msg && <p role="status" className="text-sm">{msg}</p>}
        </form>
        <section className="card space-y-2"><h2 className="font-semibold">{t("a.addresses")}</h2>
          <ul className="divide-y divide-line text-sm">{addrs.map((a) => (
            <li key={a.id} className="py-2"><b>{a.label}</b>{a.isDefault && ` · ${t("a.default")}`}<p>{a.address}</p>
              <div className="flex gap-4 text-xs">{!a.isDefault && <button type="button" className="underline" onClick={() => act(() => api(`/web/addresses/${a.id}`, { method: "PUT", body: { is_default: true } }))}>{t("a.makeDefault")}</button>}
                <button type="button" className="text-red-700 underline" onClick={() => act(() => api(`/web/addresses/${a.id}`, { method: "DELETE" }))}>{t("a.remove")}</button></div></li>))}</ul>
          <form onSubmit={addAddr} className="space-y-2"><input className="field" placeholder={t("a.label")} required maxLength={40} value={label} onChange={(e) => setLabel(e.target.value)} /><textarea className="field py-2" rows={2} placeholder={t("co.address")} required maxLength={255} value={address} onChange={(e) => setAddress(e.target.value)} /><button className="btn-ghost">{t("a.addAddress")}</button></form>
        </section>
        <section className="card space-y-2">
          {push === "off" && <button className="btn-ghost" onClick={async () => setPush(await enablePush().catch((): PushState => "off"))}>{t("push.enable")}</button>}
          {push === "on" && <p className="text-sm text-soft">{t("push.on")}</p>}
          {push === "blocked" && <p className="text-sm text-soft">{t("push.blocked")}</p>}
          {(push === "unavailable" || push === "unsupported") && <p className="text-xs text-soft">{t("push.unavailable")}</p>}
          <button className="btn-ghost" onClick={() => setLang(lang === "bn" ? "en" : "bn")}>{t("a.language")}: {lang === "bn" ? "বাংলা → English" : "English → বাংলা"}</button>
          {site?.features.reservations && <Link href="/reservations" className="btn-ghost">{t("a.reservations")}</Link>}
          <button className="btn-ghost" onClick={() => void signOut()}>{t("a.signout")}</button>
        </section>
      </main>
    </Shell>
  );
}
