"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { en, type MessageKey } from "./en";
import { bn } from "./bn";
import { setAppConfig } from "@/lib/app-config";

export type Locale = "en" | "bn";
const KEY = "ct_locale";

const dictionaries: Record<Locale, Partial<Record<MessageKey, string>>> = { en, bn };

/** Pure lookup (also used by tests): Bangla first, then English, then the key itself so a gap is visible, not blank. */
export function translate(locale: Locale, key: MessageKey, vars?: Record<string, string | number>): string {
  let text = dictionaries[locale][key] ?? en[key] ?? key;
  if (vars) for (const [k, v] of Object.entries(vars)) text = text.replaceAll(`{${k}}`, String(v));
  return text;
}

type Ctx = { locale: Locale; setLocale: (l: Locale) => void; t: (key: MessageKey, vars?: Record<string, string | number>) => string; hasChoice: boolean; setDefaultLocale: (l: Locale) => void };
const I18nContext = createContext<Ctx | null>(null);

export function I18nProvider({ children }: { children: React.ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>("en");
  const [hasChoice, setHasChoice] = useState(false);

  useEffect(() => {
    try {
      const saved = window.localStorage.getItem(KEY);
      if (saved === "en" || saved === "bn") {
        setLocaleState(saved);
        setHasChoice(true);
      }
    } catch {
      /* storage blocked: stay on the default */
    }
  }, []);

  useEffect(() => {
    setAppConfig({ locale });
    document.documentElement.lang = locale;
  }, [locale]);

  const setLocale = useCallback((l: Locale) => {
    setLocaleState(l);
    setHasChoice(true);
    try {
      window.localStorage.setItem(KEY, l);
    } catch {
      /* ignore */
    }
  }, []);

  /** Business default: applies only while the person has not picked a language on this device. */
  const setDefaultLocale = useCallback((l: Locale) => { if (!hasChoice) setLocaleState(l); }, [hasChoice]);

  const value = useMemo<Ctx>(() => ({ locale, setLocale, hasChoice, setDefaultLocale, t: (key, vars) => translate(locale, key, vars) }), [locale, setLocale, hasChoice, setDefaultLocale]);
  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n(): Ctx {
  const ctx = useContext(I18nContext);
  if (!ctx) throw new Error("useI18n must be used within I18nProvider");
  return ctx;
}

export const useT = () => useI18n().t;
