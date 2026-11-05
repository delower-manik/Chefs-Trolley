"use client";

import { Suspense, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { api, ApiError } from "@/lib/client-api";
import { normalizePhone } from "@/lib/format";
import { useApp } from "@/components/providers";
import type { Customer } from "@/lib/types";

function Login() {
  const { t, signedIn, path } = useApp();
  const router = useRouter();
  const next = useSearchParams().get("next");
  const [phone, setPhone] = useState("");
  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [step, setStep] = useState<"phone" | "code">("phone");
  const [needName, setNeedName] = useState(false);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  async function send(e?: React.FormEvent) {
    e?.preventDefault();
    const p = normalizePhone(phone);
    if (!p) return setErr("Enter a valid Bangladeshi mobile number, like 01712345678.");
    setBusy(true); setErr(null);
    try { await api("/web/auth/code", { body: { phone: p } }); setPhone(p); setStep("code"); }
    catch (x) { setErr((x as ApiError).message); } finally { setBusy(false); }
  }
  async function verify(e: React.FormEvent) {
    e.preventDefault(); setBusy(true); setErr(null);
    try {
      const r = await api<{ token: string; customer: Customer }>("/web/auth/verify", { body: { phone, code, ...(needName ? { name } : {}) } });
      signedIn(r.token, r.customer);
      router.replace(path(next && /^\/[^/\\]/.test(next) && !/^\/(en|bn)(\/|$)/.test(next) ? next : "/"));
    } catch (x) {
      const a = x as ApiError;
      if (a.code === "name_required") setNeedName(true);
      setErr(a.message);
    } finally { setBusy(false); }
  }

  return (
    <div className="mx-auto max-w-sm">
      <h1 className="mb-4 text-3xl font-bold">{t("auth.title")}</h1>
      {step === "phone" ? (
        <form onSubmit={send} className="card space-y-3">
          <label className="label" htmlFor="ph">{t("auth.phone")}</label>
          <input id="ph" className="field" inputMode="tel" autoComplete="tel" placeholder="01XXXXXXXXX" value={phone} onChange={(e) => setPhone(e.target.value)} />
          <button className="btn w-full" disabled={busy}>{t("auth.sendCode")}</button>
        </form>
      ) : (
        <form onSubmit={verify} className="card space-y-3">
          <p className="text-sm text-soft">{t("auth.sent", { phone })}</p>
          <label className="label" htmlFor="cd">{t("auth.code")}</label>
          <input id="cd" className="field tracking-[0.4em]" inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ""))} />
          {needName && (<><p className="text-sm">{t("auth.newHere")}</p><label className="label" htmlFor="nm">{t("auth.name")}</label><input id="nm" className="field" autoComplete="name" value={name} onChange={(e) => setName(e.target.value)} /></>)}
          <button className="btn w-full" disabled={busy || code.length !== 6}>{t("auth.verify")}</button>
          <div className="flex justify-between text-sm"><button type="button" className="underline" onClick={() => { setStep("phone"); setCode(""); setNeedName(false); setErr(null); }}>{t("auth.change")}</button><button type="button" className="underline" onClick={() => send()} disabled={busy}>{t("auth.resend")}</button></div>
        </form>
      )}
      {err && <p role="alert" className="mt-3 rounded-xl bg-red-50 p-3 text-sm text-red-800">{err}</p>}
    </div>
  );
}

export default function LoginPage() { return (<Suspense><Login /></Suspense>); }
