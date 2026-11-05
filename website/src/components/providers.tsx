"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { api, getToken, setToken } from "@/lib/client-api";
import { formatMoney, lineKey } from "@/lib/format";
import { useRouter } from "next/navigation";
import { bn, en, type Key } from "@/i18n/dict";
import { LOCALE_COOKIE, localePath, switchLocalePath, type Locale } from "@/lib/locale";
import type { CartLine, Customer, Site } from "@/lib/types";

type Ctx = {
  site: Site; locale: Locale; setLocale: (l: Locale) => void; path: (p: string) => string; t: (k: Key, vars?: Record<string, string | number>) => string; money: (n: number) => string;
  customer: Customer | null; authReady: boolean; signedIn: (token: string, c: Customer) => void; signOut: () => Promise<void>; refreshCustomer: () => Promise<void>;
  cart: { branchId: string | null; lines: CartLine[] }; addLine: (branchId: string, l: Omit<CartLine, "key">) => boolean; setQty: (key: string, q: number) => void; clearCart: () => void;
  cartCount: number; cartTotal: number;
};
const AppContext = createContext<Ctx | null>(null);
const CART_KEY = "ct_web_cart";

export function translate(locale: Locale, k: Key, vars?: Record<string, string | number>): string {
  let s: string = (locale === "bn" ? bn[k] : undefined) ?? en[k] ?? k;
  if (vars) for (const [a, b] of Object.entries(vars)) s = s.replaceAll(`{${a}}`, String(b));
  return s;
}

export function Providers({ site, lang, children }: { site: Site; lang: Locale; children: React.ReactNode }) {
  const router = useRouter();
  const locale = lang; // the URL decides the language (/en, /bn), so every language has its own address
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [authReady, setAuthReady] = useState(false);
  const [cart, setCart] = useState<{ branchId: string | null; lines: CartLine[] }>({ branchId: null, lines: [] });

  useEffect(() => {
    try {
      const c = window.localStorage.getItem(CART_KEY);
      if (c) setCart(JSON.parse(c));
    } catch { /* storage blocked or corrupt: start empty */ }
    if (getToken()) api<Customer>("/web/me").then(setCustomer).catch(() => setToken(null)).finally(() => setAuthReady(true));
    else setAuthReady(true);
  }, []);

  useEffect(() => { try { window.localStorage.setItem(CART_KEY, JSON.stringify(cart)); } catch { /* ignore */ } }, [cart]);

  const setLocale = useCallback((l: Locale) => {
    document.cookie = `${LOCALE_COOKIE}=${l}; path=/; max-age=31536000; samesite=lax`; // remembered for bare URLs like /menu
    router.push(switchLocalePath(window.location.pathname, l) + window.location.search);
  }, [router]);
  const path = useCallback((p: string) => localePath(locale, p), [locale]);
  const intl = locale === "bn" ? "bn-BD" : "en-BD";
  const money = useCallback((n: number) => formatMoney(n, { symbol: site.currency.symbol, decimals: site.currency.decimals, locale: intl }), [site, intl]);

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
  const signOut = useCallback(async () => { try { await api("/web/auth/logout", { method: "POST", body: {} }); } catch { /* token may already be dead */ } setToken(null); setCustomer(null); }, []);
  const refreshCustomer = useCallback(async () => { try { setCustomer(await api<Customer>("/web/me")); } catch { /* ignore */ } }, []);

  const value = useMemo<Ctx>(() => ({
    site, locale, setLocale, path, t: (k, v) => translate(locale, k, v), money, customer, authReady, signedIn, signOut, refreshCustomer,
    cart, addLine, setQty, clearCart, cartCount: cart.lines.reduce((n, l) => n + l.quantity, 0), cartTotal: cart.lines.reduce((n, l) => n + l.quantity * l.unitPrice, 0),
  }), [site, locale, setLocale, path, money, customer, authReady, signedIn, signOut, refreshCustomer, cart, addLine, setQty, clearCart]);
  return <AppContext.Provider value={value}>{children}</AppContext.Provider>;
}

export function useApp(): Ctx {
  const c = useContext(AppContext);
  if (!c) throw new Error("useApp must be used inside Providers");
  return c;
}
