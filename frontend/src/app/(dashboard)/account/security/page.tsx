"use client";

import { useEffect, useState } from "react";
import QRCode from "qrcode";
import { ApiError } from "@/lib/api/client";
import {
  changePassword, confirmMfa, disableMfa, regenerateRecoveryCodes, revokeOtherSessions, revokeSession, startMfa, updateProfile, useAccount, useSessions,
} from "@/hooks/use-account";
import { formatDateTime } from "@/lib/format";

const errText = (e: unknown, fallback: string) => (e instanceof ApiError ? e.message : fallback);

export default function SecurityPage() {
  const { account, refresh } = useAccount();
  return (
    <section className="max-w-3xl mx-auto space-y-5">
      <div>
        <h2 className="text-2xl font-bold text-strong">My Security</h2>
        <p className="text-sm text-text-secondary">
          {account ? `${account.name} · ${account.email}` : "Loading…"}
          {account?.lastLoginAt && ` · last sign-in ${formatDateTime(account.lastLoginAt)}${account.lastLoginIp ? ` from ${account.lastLoginIp}` : ""}`}
        </p>
      </div>
      <ProfileCard name={account?.name ?? ""} onSaved={refresh} />
      <PasswordCard />
      <MfaCard enabled={!!account?.mfaEnabled} codesLeft={account?.recoveryCodesLeft ?? 0} onChanged={refresh} />
      <SessionsCard />
    </section>
  );
}

function Msg({ m }: { m: { ok: boolean; text: string } | null }) {
  if (!m) return null;
  return (
    <div role="alert" className={`text-xs rounded-lg px-3 py-2 border ${m.ok ? "bg-success/10 border-success/30 text-success" : "bg-danger/10 border-danger/30 text-danger"}`}>
      {m.text}
    </div>
  );
}

function ProfileCard({ name, onSaved }: { name: string; onSaved: () => void }) {
  const [value, setValue] = useState("");
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  useEffect(() => setValue(name), [name]);
  return (
    <form
      className="card p-5 space-y-3"
      onSubmit={async (e) => {
        e.preventDefault();
        try {
          await updateProfile(value);
          setMsg({ ok: true, text: "Name updated." });
          onSaved();
        } catch (err) {
          setMsg({ ok: false, text: errText(err, "Couldn't save.") });
        }
      }}
    >
      <h3 className="font-bold text-strong">Profile</h3>
      <Msg m={msg} />
      <div className="flex gap-2">
        <input value={value} onChange={(e) => setValue(e.target.value)} className="input-field flex-1" maxLength={150} aria-label="Your name" />
        <button className="btn-secondary" disabled={value.trim().length < 2 || value === name}>Save</button>
      </div>
    </form>
  );
}

function PasswordCard() {
  const [cur, setCur] = useState("");
  const [pw, setPw] = useState("");
  const [again, setAgain] = useState("");
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  return (
    <form
      className="card p-5 space-y-3"
      onSubmit={async (e) => {
        e.preventDefault();
        try {
          await changePassword(cur, pw, again);
          setMsg({ ok: true, text: "Password changed. You were signed out of every other device." });
          setCur(""); setPw(""); setAgain("");
        } catch (err) {
          setMsg({ ok: false, text: errText(err, "Couldn't change the password.") });
        }
      }}
    >
      <h3 className="font-bold text-strong">Change password</h3>
      <Msg m={msg} />
      <input type="password" autoComplete="current-password" value={cur} onChange={(e) => setCur(e.target.value)} className="input-field" placeholder="Current password" required />
      <input type="password" autoComplete="new-password" value={pw} onChange={(e) => setPw(e.target.value)} className="input-field" placeholder="New password (10+ characters, letters and numbers)" required minLength={10} />
      <input type="password" autoComplete="new-password" value={again} onChange={(e) => setAgain(e.target.value)} className="input-field" placeholder="New password again" required />
      <button className="btn-primary" disabled={!cur || !pw || pw !== again}>Change password</button>
    </form>
  );
}

function MfaCard({ enabled, codesLeft, onChanged }: { enabled: boolean; codesLeft: number; onChanged: () => void }) {
  const [setup, setSetup] = useState<{ secret: string; uri: string } | null>(null);
  const [qr, setQr] = useState<string | null>(null);
  const [code, setCode] = useState("");
  const [codes, setCodes] = useState<string[] | null>(null);
  const [proof, setProof] = useState({ password: "", code: "" });
  const [mode, setMode] = useState<null | "disable" | "regen">(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

  useEffect(() => {
    if (!setup) return setQr(null);
    QRCode.toDataURL(setup.uri, { margin: 1, width: 180 }).then(setQr).catch(() => setQr(null));
  }, [setup]);

  return (
    <div className="card p-5 space-y-3">
      <div className="flex items-center justify-between">
        <h3 className="font-bold text-strong">Two-step sign-in</h3>
        <span className={`badge ${enabled ? "bg-success/15 text-success" : "bg-surface-hover text-text-secondary"}`}>{enabled ? "On" : "Off"}</span>
      </div>
      <Msg m={msg} />

      {codes && (
        <div className="border border-warning/40 bg-warning/10 rounded-lg p-3 space-y-2">
          <div className="text-sm text-warning font-medium">Save these recovery codes now — they are shown only once.</div>
          <div className="grid grid-cols-2 gap-1 font-mono text-sm text-strong">{codes.map((c) => <span key={c}>{c}</span>)}</div>
          <p className="text-[11px] text-text-secondary">Each works one time if you lose your phone. Keep them somewhere safe, not on this computer.</p>
          <div className="flex gap-3">
            <button className="text-xs text-primary-fg" onClick={() => void navigator.clipboard?.writeText(codes.join("\n"))}>Copy</button>
            <button className="text-xs text-text-secondary" onClick={() => setCodes(null)}>I have saved them</button>
          </div>
        </div>
      )}

      {!enabled && !setup && (
        <>
          <p className="text-sm text-text-secondary">Adds a 6-digit code from an authenticator app (Google Authenticator, Authy, 1Password…) to sign-in. Strongly recommended for owners and managers.</p>
          <button className="btn-primary" onClick={async () => { setMsg(null); try { setSetup(await startMfa()); } catch (e) { setMsg({ ok: false, text: errText(e, "Couldn't start setup.") }); } }}>Set up</button>
        </>
      )}

      {!enabled && setup && (
        <form
          className="space-y-3"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              setCodes(await confirmMfa(code));
              setSetup(null); setCode(""); setMsg({ ok: true, text: "Two-step sign-in is on." });
              onChanged();
            } catch (err) {
              setMsg({ ok: false, text: errText(err, "That code didn't work.") });
            }
          }}
        >
          <p className="text-sm text-text-secondary">1. Scan this with your authenticator app (or type the key). 2. Enter the 6-digit code it shows.</p>
          {qr && (
            <div className="inline-block bg-white p-2 rounded-lg">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={qr} alt="Authenticator QR code" width={180} height={180} />
            </div>
          )}
          <div className="text-xs text-text-secondary">Key: <span className="font-mono text-strong select-all">{setup.secret}</span></div>
          <div className="flex gap-2">
            <input value={code} onChange={(e) => setCode(e.target.value)} inputMode="numeric" autoComplete="one-time-code" className="input-field w-40 tracking-widest" placeholder="123456" maxLength={7} required />
            <button className="btn-primary" disabled={code.replace(/\s/g, "").length !== 6}>Turn on</button>
            <button type="button" className="btn-secondary" onClick={() => setSetup(null)}>Cancel</button>
          </div>
        </form>
      )}

      {enabled && (
        <div className="space-y-3">
          <p className="text-sm text-text-secondary">{codesLeft} recovery code{codesLeft === 1 ? "" : "s"} left.{codesLeft <= 2 && " Make new ones soon."}</p>
          {mode === null ? (
            <div className="flex gap-3">
              <button className="btn-secondary text-sm" onClick={() => setMode("regen")}>New recovery codes</button>
              <button className="text-sm text-danger" onClick={() => setMode("disable")}>Turn off…</button>
            </div>
          ) : (
            <form
              className="space-y-2"
              onSubmit={async (e) => {
                e.preventDefault();
                try {
                  if (mode === "regen") setCodes(await regenerateRecoveryCodes(proof.password, proof.code));
                  else await disableMfa(proof.password, proof.code);
                  setMsg({ ok: true, text: mode === "regen" ? "New recovery codes created; the old ones no longer work." : "Two-step sign-in is off." });
                  setMode(null); setProof({ password: "", code: "" });
                  onChanged();
                } catch (err) {
                  setMsg({ ok: false, text: errText(err, "That didn't work.") });
                }
              }}
            >
              <p className="text-xs text-text-secondary">Confirm it is you: your password and a current code (or a recovery code).</p>
              <input type="password" autoComplete="current-password" value={proof.password} onChange={(e) => setProof({ ...proof, password: e.target.value })} className="input-field" placeholder="Password" required />
              <input value={proof.code} onChange={(e) => setProof({ ...proof, code: e.target.value })} className="input-field" placeholder="Authenticator or recovery code" required />
              <div className="flex gap-2">
                <button className={mode === "disable" ? "btn-secondary text-danger" : "btn-primary"}>{mode === "disable" ? "Turn off two-step" : "Create new codes"}</button>
                <button type="button" className="btn-secondary" onClick={() => setMode(null)}>Cancel</button>
              </div>
            </form>
          )}
        </div>
      )}
    </div>
  );
}

function SessionsCard() {
  const { sessions, refresh } = useSessions();
  return (
    <div className="card p-5 space-y-3">
      <div className="flex items-center justify-between">
        <h3 className="font-bold text-strong">Where you&apos;re signed in</h3>
        {sessions.length > 1 && (
          <button className="text-xs text-danger" onClick={async () => { await revokeOtherSessions(); await refresh(); }}>Sign out everywhere else</button>
        )}
      </div>
      <ul className="divide-y divide-border text-sm">
        {sessions.map((s) => (
          <li key={s.id} className="py-2 flex items-center justify-between gap-3">
            <div className="min-w-0">
              <div className="text-strong truncate">{s.device ?? "Unknown device"} {s.current && <span className="badge bg-primary/15 text-primary-fg ml-1">This device</span>}</div>
              <div className="text-xs text-text-secondary">{s.ip ?? "—"} · started {formatDateTime(s.startedAt)} · last active {formatDateTime(s.lastActiveAt)}</div>
            </div>
            {!s.current && <button className="text-xs text-danger shrink-0" onClick={async () => { await revokeSession(s.id); await refresh(); }}>Sign out</button>}
          </li>
        ))}
      </ul>
    </div>
  );
}
