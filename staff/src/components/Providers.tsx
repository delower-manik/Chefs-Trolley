"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { api, ApiError, getTokens, setTokens } from "@/lib/api";
import { forgetPush } from "@/lib/push";
import { translate, type Key, type Lang } from "@/lib/i18n";
import type { Employee, Me } from "@/lib/types";

type Ctx = {
  lang: Lang; setLang: (l: Lang) => void; t: (k: Key, v?: Record<string, string | number>) => string; locale: string;
  me: Me | null; employee: Employee | null; ready: boolean; online: boolean; can: (p: string) => boolean;
  reloadEmployee: () => Promise<void>; signOut: () => Promise<void>; onSignedIn: () => Promise<boolean>; home: () => string;
};
const C = createContext<Ctx | null>(null);
export const useApp = () => { const c = useContext(C); if (!c) throw new Error("useApp outside Providers"); return c; };

export default function Providers({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const path = usePathname();
  const [lang, setLangState] = useState<Lang>("bn");
  const [me, setMe] = useState<Me | null>(null);
  const [employee, setEmployee] = useState<Employee | null>(null);
  const [ready, setReady] = useState(false);
  const [online, setOnline] = useState(true);

  useEffect(() => {
    try { const l = localStorage.getItem("ct_staff_lang"); if (l === "en" || l === "bn") setLangState(l); } catch { /* default */ }
    setOnline(navigator.onLine);
    const on = () => setOnline(true), off = () => setOnline(false);
    window.addEventListener("online", on); window.addEventListener("offline", off);
    return () => { window.removeEventListener("online", on); window.removeEventListener("offline", off); };
  }, []);
  useEffect(() => { document.documentElement.lang = lang; }, [lang]);

  const reloadEmployee = useCallback(async () => {
    try { setEmployee(await api<Employee>("/staff/me")); } catch (e) { if (e instanceof ApiError && (e.status === 401 || e.status === 403)) { setEmployee(null); setMe(null); } }
  }, []);
  const load = useCallback(async () => {
    try { const [m, e] = await Promise.all([api<Me>("/auth/me"), api<Employee>("/staff/me")]); setMe(m); setEmployee(e); return true; }
    catch { return false; }
  }, []);

  useEffect(() => { (async () => { if (getTokens()) await load(); setReady(true); })(); }, [load]);
  useEffect(() => { if (ready && !employee && path !== "/login") router.replace("/login"); }, [ready, employee, path, router]);

  const can = useCallback((p: string) => !!me?.permissions.includes(p), [me]);
  const home = useCallback(() => (can("orders.create") ? "/waiter" : can("kds.access") ? "/kitchen" : "/attendance"), [can]);
  const setLang = useCallback((l: Lang) => { setLangState(l); try { localStorage.setItem("ct_staff_lang", l); } catch { /* ignore */ } }, []);
  const signOut = useCallback(async () => {
    await forgetPush();
    try { await api("/auth/logout", { method: "POST", body: {} }); } catch { /* token may already be dead */ }
    setTokens(null); setMe(null); setEmployee(null); router.replace("/login");
  }, [router]);
  const onSignedIn = useCallback(async () => { if (await load()) return true; setTokens(null); return false; }, [load]);

  // Stable identity per language: pages list `t` in effect dependencies, and a new function every render would re-run those effects forever.
  const t = useCallback((k: Key, v?: Record<string, string | number>) => translate(lang, k, v), [lang]);

  const value = useMemo<Ctx>(() => ({
    lang, setLang, t, locale: lang === "bn" ? "bn-BD" : "en-BD",
    me, employee, ready, online, can, reloadEmployee, signOut, onSignedIn, home,
  }), [t, lang, setLang, me, employee, ready, online, can, reloadEmployee, signOut, onSignedIn, home]);
  return <C.Provider value={value}>{children}</C.Provider>;
}
