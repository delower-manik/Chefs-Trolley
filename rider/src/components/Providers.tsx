"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { api, ApiError, getTokens, setTokens } from "@/lib/api";
import { forgetPush } from "@/lib/push";
import { translate, type Key, type Lang } from "@/lib/i18n";
import type { Profile } from "@/lib/types";

type Ctx = {
  lang: Lang; setLang: (l: Lang) => void; t: (k: Key, v?: Record<string, string | number>) => string; locale: string;
  profile: Profile | null; ready: boolean; online: boolean; reload: () => Promise<void>; signOut: () => Promise<void>; onSignedIn: () => Promise<boolean>;
};
const C = createContext<Ctx | null>(null);
export const useApp = () => { const c = useContext(C); if (!c) throw new Error("useApp outside Providers"); return c; };

export default function Providers({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const path = usePathname();
  const [lang, setLangState] = useState<Lang>("bn");
  const [profile, setProfile] = useState<Profile | null>(null);
  const [ready, setReady] = useState(false);
  const [online, setOnline] = useState(true);

  useEffect(() => {
    try { const l = localStorage.getItem("ct_rider_lang"); if (l === "en" || l === "bn") setLangState(l); } catch { /* default */ }
    setOnline(navigator.onLine);
    const on = () => setOnline(true), off = () => setOnline(false);
    window.addEventListener("online", on); window.addEventListener("offline", off);
    return () => { window.removeEventListener("online", on); window.removeEventListener("offline", off); };
  }, []);
  useEffect(() => { document.documentElement.lang = lang; }, [lang]);

  const reload = useCallback(async () => {
    try { setProfile(await api<Profile>("/rider/me")); }
    catch (e) { if (e instanceof ApiError && (e.status === 401 || e.status === 403)) setProfile(null); /* network errors keep what we have */ }
  }, []);

  useEffect(() => {
    (async () => {
      if (getTokens()) await reload();
      setReady(true);
    })();
  }, [reload]);

  // No profile after loading => send to login (except on the login page itself).
  useEffect(() => { if (ready && !profile && path !== "/login") router.replace("/login"); }, [ready, profile, path, router]);

  const setLang = useCallback((l: Lang) => { setLangState(l); try { localStorage.setItem("ct_rider_lang", l); } catch { /* ignore */ } }, []);
  const signOut = useCallback(async () => {
    await forgetPush();
    try { await api("/auth/logout", { method: "POST", body: {} }); } catch { /* token may already be dead */ }
    setTokens(null); setProfile(null); router.replace("/login");
  }, [router]);
  /** After a successful login: confirm this account really is a rider. */
  const onSignedIn = useCallback(async () => {
    try { setProfile(await api<Profile>("/rider/me")); return true; }
    catch { setTokens(null); return false; }
  }, []);

  // Stable identity per language: pages list `t` in effect dependencies, and a new function every render would re-run those effects forever.
  const t = useCallback((k: Key, v?: Record<string, string | number>) => translate(lang, k, v), [lang]);

  const value = useMemo<Ctx>(() => ({
    lang, setLang, t, locale: lang === "bn" ? "bn-BD" : "en-BD",
    profile, ready, online, reload, signOut, onSignedIn,
  }), [t, lang, setLang, profile, ready, online, reload, signOut, onSignedIn]);
  return <C.Provider value={value}>{children}</C.Provider>;
}
