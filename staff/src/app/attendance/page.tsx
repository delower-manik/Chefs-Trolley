"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { timeOf } from "@/lib/format";
import { enablePush, pushStatus, type PushState } from "@/lib/push";
import type { Day } from "@/lib/types";
import { useApp } from "@/components/Providers";
import Shell from "@/components/Shell";

export default function Attendance() {
  const { t, locale, employee, reloadEmployee, signOut } = useApp();
  const [days, setDays] = useState<Day[]>([]);
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [push, setPush] = useState<PushState>("off");

  const load = useCallback(async () => { try { setDays(await api<Day[]>("/staff/attendance")); } catch { /* shown on action */ } }, []);
  useEffect(() => { void load(); setPush(pushStatus()); }, [load]);

  async function act(path: "clock-in" | "clock-out") {
    setBusy(true); setErr(null);
    try { await api(`/staff/${path}`, { body: {} }); await reloadEmployee(); await load(); }
    catch (e) { setErr(e instanceof ApiError && e.status ? e.message : t("err.network")); }
    finally { setBusy(false); }
  }
  const today = employee?.today;
  const finished = !!today?.checkOutTime;

  return (
    <Shell>
      <main className="space-y-4">
        <section className="card space-y-3 text-center">
          <h1 className="text-lg font-bold">{t("a.title")}</h1>
          <p>{employee?.clockedIn ? `${t("a.status.in")} · ${t("a.since", { t: timeOf(today?.checkInTime ?? null, locale) })}` : finished ? t("a.done") : t("a.status.out")}</p>
          {!employee?.clockedIn && !finished && <button className="btn" disabled={busy} onClick={() => act("clock-in")}>{t("a.in")}</button>}
          {employee?.clockedIn && <button className="btn-danger" disabled={busy} onClick={() => act("clock-out")}>{t("a.out")}</button>}
          {err && <p role="alert" className="text-sm text-red-700">{err}</p>}
          <p className="text-xs text-soft">{t("a.note")}</p>
        </section>
        <section className="card">
          <h2 className="mb-2 font-semibold">{t("a.history")}</h2>
          <ul className="divide-y divide-line text-sm">{days.map((d) => (
            <li key={d.date} className="flex justify-between py-2"><span>{d.date}</span><span className="text-soft">{d.status.replace("_", " ")} · {timeOf(d.checkInTime, locale)}–{timeOf(d.checkOutTime, locale)}</span></li>
          ))}</ul>
        </section>
        <section className="card space-y-2">
          {push === "off" && <button className="btn-ghost" onClick={async () => setPush(await enablePush().catch((): PushState => "off"))}>{t("push.enable")}</button>}
          {push === "on" && <p className="text-sm text-soft">{t("push.on")}</p>}
          {push === "blocked" && <p className="text-sm text-soft">{t("push.blocked")}</p>}
          {(push === "unavailable" || push === "unsupported") && <p className="text-xs text-soft">{t("push.unavailable")}</p>}
        </section>
        <button className="btn-ghost" onClick={() => void signOut()}>{t("signout")}</button>
      </main>
    </Shell>
  );
}
