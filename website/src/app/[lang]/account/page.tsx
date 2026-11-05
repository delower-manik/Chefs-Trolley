"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { api, ApiError } from "@/lib/client-api";
import { useApp } from "@/components/providers";
import RequireLogin from "@/components/RequireLogin";
import type { Address, Customer } from "@/lib/types";
import type { Locale } from "@/lib/locale";

type Loyalty = { points: number; history: { type: string; points: number; balance: number; reason: string | null; date: string }[] };

function Account() {
  const { t, customer, refreshCustomer, signOut, locale, setLocale, path } = useApp();
  const router = useRouter();
  const [f, setF] = useState({ name: customer?.name ?? "", email: customer?.email ?? "" });
  const [msg, setMsg] = useState<string | null>(null);
  const [loy, setLoy] = useState<Loyalty | null>(null);
  useEffect(() => { api<Loyalty>("/web/loyalty").then(setLoy).catch(() => setLoy(null)); }, []);

  async function save(e: React.FormEvent) {
    e.preventDefault(); setMsg(null);
    try { await api<Customer>("/web/me", { method: "PUT", body: { name: f.name, email: f.email || null, locale } }); await refreshCustomer(); setMsg(t("acct.saved")); }
    catch (x) { setMsg((x as ApiError).message); }
  }
  return (
    <div className="mx-auto max-w-xl space-y-4">
      <h1 className="text-3xl font-bold">{t("acct.title")}</h1>
      <div className="card"><p className="text-sm text-soft">{t("acct.points")}</p><p className="text-3xl font-bold text-brand">{loy?.points ?? customer?.loyaltyPoints ?? 0}</p></div>
      <form onSubmit={save} className="card space-y-3">
        <p className="text-sm text-soft">{customer?.phone}</p>
        <div><label className="label" htmlFor="n">{t("acct.name")}</label><input id="n" className="field" required minLength={2} value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></div>
        <div><label className="label" htmlFor="e">{t("acct.email")}</label><input id="e" type="email" className="field" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} /></div>
        <div className="flex items-center gap-2"><label className="label !mb-0" htmlFor="lg">{t("acct.language")}</label><select id="lg" className="field !w-auto" value={locale} onChange={(e) => setLocale(e.target.value as Locale)}><option value="en">English</option><option value="bn">বাংলা</option></select></div>
        <button className="btn">{t("acct.save")}</button>
        {msg && <p role="status" className="text-sm">{msg}</p>}
      </form>
      <AddressBook />
      {loy && loy.history.length > 0 && (
        <section className="card"><h2 className="mb-2 font-semibold">{t("acct.history")}</h2>
          <ul className="space-y-1 text-sm">{loy.history.map((h, i) => (<li key={i} className="flex justify-between"><span>{h.reason ?? h.type}</span><span className={h.points >= 0 ? "text-green-700" : "text-red-700"}>{h.points > 0 ? "+" : ""}{h.points}</span></li>))}</ul></section>
      )}
      <button className="btn-ghost" onClick={async () => { await signOut(); router.replace(path("/")); }}>{t("nav.signout")}</button>
    </div>
  );
}
export default function AccountPage() { return (<RequireLogin next="/account"><Account /></RequireLogin>); }

function AddressBook() {
  const { t } = useApp();
  const [list, setList] = useState<Address[]>([]);
  const [label, setLabel] = useState("");
  const [address, setAddress] = useState("");
  const [err, setErr] = useState<string | null>(null);
  const load = () => api<Address[]>("/web/addresses").then(setList).catch(() => setList([]));
  useEffect(() => { load(); }, []);
  async function add(e: React.FormEvent) {
    e.preventDefault(); setErr(null);
    try { await api("/web/addresses", { body: { label, address } }); setLabel(""); setAddress(""); load(); } catch (x) { setErr((x as ApiError).message); }
  }
  const act = async (fn: () => Promise<unknown>) => { setErr(null); try { await fn(); load(); } catch (x) { setErr((x as ApiError).message); } };
  return (
    <section className="card space-y-3">
      <h2 className="font-semibold">{t("acct.addresses")}</h2>
      <ul className="space-y-2">
        {list.map((a) => (
          <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 text-sm">
            <span><b>{a.label}</b>{a.isDefault && <span className="ml-2 rounded-full bg-brand/10 px-2 py-0.5 text-xs text-brand">{t("acct.default")}</span>}<br /><span className="text-soft">{a.address}</span></span>
            <span className="flex gap-2">
              {!a.isDefault && <button type="button" className="underline" onClick={() => act(() => api(`/web/addresses/${a.id}`, { method: "PUT", body: { is_default: true } }))}>{t("acct.makeDefault")}</button>}
              <button type="button" className="underline text-red-700" onClick={() => act(() => api(`/web/addresses/${a.id}`, { method: "DELETE" }))}>{t("acct.remove")}</button>
            </span>
          </li>
        ))}
      </ul>
      <form onSubmit={add} className="space-y-2">
        <input className="field" placeholder={t("acct.label")} required maxLength={40} value={label} onChange={(e) => setLabel(e.target.value)} />
        <input className="field" placeholder={t("co.address")} required minLength={5} maxLength={255} value={address} onChange={(e) => setAddress(e.target.value)} />
        <button className="btn-ghost">{t("acct.addAddress")}</button>
      </form>
      {err && <p role="alert" className="text-sm text-red-800">{err}</p>}
    </section>
  );
}
