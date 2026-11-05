"use client";

import { Suspense, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { api, ApiError } from "@/lib/client-api";
import { normalizePhone } from "@/lib/format";
import type { Customer } from "@/lib/types";
import { useApp } from "@/components/Providers";
import Shell from "@/components/Shell";

function Login() {
  const { t, signedIn } = useApp();
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
    if (!p) return setErr(t("login.badPhone"));
    setBusy(true); setErr(null);
    try { await api("/web/auth/code", { body: { phone: p } }); setPhone(p); setStep("code"); }
    catch (x) { setErr((x as ApiError).message); } finally { setBusy(false); }
  }
  async function verify(e: React.FormEvent) {
    e.preventDefault(); setBusy(true); setErr(null);
    try {
      const r = await api<{ token: string; customer: Customer }>("/web/auth/verify", { body: { phone, code, ...(needName ? { name } : {}) } });
      signedIn(r.token, r.customer);
      router.replace(next && /^\/[^/\\]/.test(next) ? next : "/");
    } catch (x) {
      const a = x as ApiError;
      if (a.code === "name_required") setNeedName(true); // first sign-in: the server asks for a name
      setErr(a.message);
    } finally { setBusy(false); }
  }
  return (
    <main className="space-y-3 pt-6">
      <h1 className="text-2xl font-bold">{t("login.title")}</h1>
      {step === "phone" ? (
        <form onSubmit={send} className="space-y-3">
          <input className="field" inputMode="tel" autoComplete="tel" placeholder={t("login.phone")} value={phone} onChange={(e) => setPhone(e.target.value)} required />
          {err && <p role="alert" className="text-sm text-red-700">{err}</p>}
          <button className="btn" disabled={busy}>{busy ? t("loading") : t("login.send")}</button>
        </form>
      ) : (
        <form onSubmit={verify} className="space-y-3">
          <input className="field" inputMode="numeric" autoComplete="one-time-code" placeholder={t("login.code")} value={code} onChange={(e) => setCode(e.target.value)} required />
          {needName && <input className="field" placeholder={t("login.name")} value={name} onChange={(e) => setName(e.target.value)} required />}
          {err && <p role="alert" className="text-sm text-red-700">{err}</p>}
          <button className="btn" disabled={busy}>{busy ? t("loading") : t("login.verify")}</button>
          <button type="button" className="text-sm underline" onClick={() => { setStep("phone"); setCode(""); setErr(null); }}>{t("login.resend")}</button>
        </form>
      )}
    </main>
  );
}
export default function Page() { return <Shell><Suspense fallback={null}><Login /></Suspense></Shell>; }
