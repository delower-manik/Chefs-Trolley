"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { api, ApiError, setTokens } from "@/lib/api";
import type { LoginResult } from "@/lib/types";
import { useApp } from "@/components/Providers";

export default function LoginPage() {
  const { t, lang, setLang, onSignedIn } = useApp();
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [challenge, setChallenge] = useState<string | null>(null);
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  async function finish(): Promise<void> {
    if (await onSignedIn()) router.replace("/");
    else setErr(t("login.notRider"));
  }
  async function submit(e: React.FormEvent) {
    e.preventDefault(); setBusy(true); setErr(null);
    try {
      const r = challenge
        ? await api<LoginResult>("/auth/mfa", { auth: false, body: { challenge, code } })
        : await api<LoginResult>("/auth/login", { auth: false, body: { email, password } });
      if ("mfaRequired" in r) { setChallenge(r.challenge); return; }
      setTokens(r.tokens);
      await finish();
    } catch (e2) { setErr(e2 instanceof ApiError && e2.status !== 0 ? e2.message : t("err.network")); }
    finally { setBusy(false); }
  }

  return (
    <main className="pt-10">
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold">{t("login.title")}</h1>
        <button type="button" className="text-sm underline" onClick={() => setLang(lang === "bn" ? "en" : "bn")}>{lang === "bn" ? "English" : "বাংলা"}</button>
      </div>
      <form onSubmit={submit} className="space-y-3">
        {challenge ? (
          <input className="field" inputMode="numeric" autoComplete="one-time-code" placeholder={t("login.code")} value={code} onChange={(e) => setCode(e.target.value)} required />
        ) : (<>
          <input className="field" type="email" autoComplete="username" placeholder={t("login.email")} value={email} onChange={(e) => setEmail(e.target.value)} required />
          <input className="field" type="password" autoComplete="current-password" placeholder={t("login.password")} value={password} onChange={(e) => setPassword(e.target.value)} required />
        </>)}
        {err && <p role="alert" className="text-sm text-red-700">{err}</p>}
        <button className="btn" disabled={busy}>{busy ? t("loading") : t("login.submit")}</button>
      </form>
      <p className="mt-6 text-xs text-soft">{t("install")}</p>
    </main>
  );
}
