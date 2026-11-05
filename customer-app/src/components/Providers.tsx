"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { api, getToken, setToken } from "@/lib/client-api";
import { forgetPush } from "@/lib/push";
import { formatMoney, hexToRgbChannels, lineKey, safeColor } from "@/lib/format";
import { translate, type Key, type Lang } from "@/lib/i18n";
import type { CartLine, Customer, Site } from "@/lib/types";

type Cart = { branchId: string | null; lines: CartLine[] };
type Ctx = {
  site: Site | null; siteError: boolean; lang: Lang; setLang: (l: Lang) => void; t: (k: Key, v?: Record<string, string | number>) => string; money: (n: number) => string;
  customer: Customer | null; ready: boolean; online: boolean; signedIn: (token: string, c: Customer) => void; signOut: () => Promise<void>; refreshCustomer: () => Promise<void>;
  cart: Cart; addLine: (branchId: string, l: Omit<CartLine, "key">) => boolean; setQty: (key: string, q: number) => void; clearCart: () => void; cartCount: number; cartTotal: number;
};
const C = createContext<Ctx | null>(null);
export const useApp = () => { const c = useContext(C); if (!c) throw new Error("useApp outside Providers"); return c; };
const CART_KEY = "ct_app_cart";

export default function Providers({ children }: { children: React.ReactNode }) {
  const [site, setSite] = useState<Site | null>(null);
  const [siteError, setSiteError] = useState(false);
  const [lang, setLangState] = useState<Lang>("bn");
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [ready, setReady] = useState(false);
  const [online, setOnline] = useState(true);
  const [cart, setCart] = useState<Cart>({ branchId: null, lines: [] });

  useEffect(() => {
    let saved: Lang | null = null;
    try { const l = localStorage.getItem("ct_app_lang"); if (l === "en" || l === "bn") saved = l; const c = localStorage.getItem(CART_KEY); if (c) setCart(JSON.parse(c)); } catch { /* start fresh */ }
    if (saved) setLangState(saved);
    setOnline(navigator.onLine);
    const on = () => setOnline(true), off = () => setOnline(false);
    window.addEventListener("online", on); window.addEventListener("offline", off);
    api<Site>("/web/site").then((s) => { setSite(s); if (!saved && (s.locale === "en" || s.locale === "bn")) setLangState(s.locale); const c = safeColor(s.primaryColor); if (c) document.documentElement.style.setProperty("--brand", hexToRgbChannels(c)); }).catch(() => setSiteError(true));
    (getToken() ? api<Customer>("/web/me").then(setCustomer).catch(() => setToken(null)) : Promise.resolve()).finally(() => setReady(true));
    return () => { window.removeEventListener("online", on); window.removeEventListener("offline", off); };
  }, []);
  useEffect(() => { try { localStorage.setItem(CART_KEY, JSON.stringify(cart)); } catch { /* ignore */ } }, [cart]);
  useEffect(() => { document.documentElement.lang = lang; }, [lang]);

  const setLang = useCallback((l: Lang) => { setLangState(l); try { localStorage.setItem("ct_app_lang", l); } catch { /* ignore */ } }, []);
  const money = useCallback((n: number) => formatMoney(n, { symbol: site?.currency.symbol ?? "৳", decimals: site?.currency.decimals ?? 0, locale: lang === "bn" ? "bn-BD" : "en-BD" }), [site, lang]);

  const addLine = useCallback((branchId: string, l: Omit<CartLine, "key">): boolean => {
    let switched = false;
    setCart((c) => {
      const base = c.branchId && c.branchId !== branchId ? ((switched = true), { branchId, lines: [] as CartLine[] }) : { branchId, lines: c.lines };
      const key = lineKey(l.itemId ?? l.comboId ?? "", l.modifierIds, l.notes);
      const found = base.lines.find((x) => x.key === key);
      const lines = found ? base.lines.map((x) => (x.key === key ? { ...x, quantity: Math.min(20, x.quantity + l.quantity) } : x)) : [...base.lines, { ...l, key }];
      return { branchId, lines };
    });
    return switched;
  }, []);
  const setQty = useCallback((key: string, q: number) => setCart((c) => {
    const lines = q <= 0 ? c.lines.filter((x) => x.key !== key) : c.lines.map((x) => (x.key === key ? { ...x, quantity: Math.min(20, q) } : x));
    return { branchId: lines.length ? c.branchId : null, lines };
  }), []);
  const clearCart = useCallback(() => setCart({ branchId: null, lines: [] }), []);

  const signedIn = useCallback((token: string, c: Customer) => { setToken(token); setCustomer(c); }, []);
  const signOut = useCallback(async () => {
    await forgetPush();
    try { await api("/web/auth/logout", { method: "POST", body: {} }); } catch { /* token may already be dead */ }
    setToken(null); setCustomer(null);
  }, []);
  const refreshCustomer = useCallback(async () => { try { setCustomer(await api<Customer>("/web/me")); } catch { /* ignore */ } }, []);

  // Stable identity per language: pages list `t` in effect dependencies, and a new function every render would re-run those effects forever.
  const t = useCallback((k: Key, v?: Record<string, string | number>) => translate(lang, k, v), [lang]);

  const value = useMemo<Ctx>(() => ({
    site, siteError, lang, setLang, t, money, customer, ready, online, signedIn, signOut, refreshCustomer,
    cart, addLine, setQty, clearCart, cartCount: cart.lines.reduce((n, l) => n + l.quantity, 0), cartTotal: cart.lines.reduce((n, l) => n + l.quantity * l.unitPrice, 0),
  }), [t, site, siteError, lang, setLang, money, customer, ready, online, signedIn, signOut, refreshCustomer, cart, addLine, setQty, clearCart]);
  return <C.Provider value={value}>{children}</C.Provider>;
}
