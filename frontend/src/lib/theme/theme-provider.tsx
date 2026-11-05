"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";

export type ThemePreference = "system" | "light" | "dark";
const KEY = "ct_theme";

/** Runs before first paint (see layout.tsx) so a light-mode user never sees a dark flash. Kept in sync with resolve(). */
export const THEME_INIT_SCRIPT = `(function(){try{var p=localStorage.getItem('${KEY}')||'system';var l=p==='light'||(p==='system'&&window.matchMedia('(prefers-color-scheme: light)').matches);document.documentElement.setAttribute('data-theme',l?'light':'dark');}catch(e){}})();`;

export function resolveTheme(pref: ThemePreference, systemPrefersLight: boolean): "light" | "dark" {
  return pref === "light" || (pref === "system" && systemPrefersLight) ? "light" : "dark";
}

type Ctx = { preference: ThemePreference; setPreference: (p: ThemePreference) => void; resolved: "light" | "dark" };
const ThemeContext = createContext<Ctx | null>(null);

export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const [preference, setPref] = useState<ThemePreference>("system");
  const [systemLight, setSystemLight] = useState(false);

  useEffect(() => {
    try {
      const saved = window.localStorage.getItem(KEY);
      if (saved === "light" || saved === "dark" || saved === "system") setPref(saved);
    } catch {
      /* ignore */
    }
    const mq = window.matchMedia("(prefers-color-scheme: light)");
    setSystemLight(mq.matches);
    const on = (e: MediaQueryListEvent) => setSystemLight(e.matches);
    mq.addEventListener("change", on);
    return () => mq.removeEventListener("change", on);
  }, []);

  const resolved = resolveTheme(preference, systemLight);
  useEffect(() => {
    document.documentElement.setAttribute("data-theme", resolved);
  }, [resolved]);

  const setPreference = useCallback((p: ThemePreference) => {
    setPref(p);
    try {
      window.localStorage.setItem(KEY, p);
    } catch {
      /* ignore */
    }
  }, []);

  const value = useMemo(() => ({ preference, setPreference, resolved }), [preference, setPreference, resolved]);
  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme(): Ctx {
  const ctx = useContext(ThemeContext);
  if (!ctx) throw new Error("useTheme must be used within ThemeProvider");
  return ctx;
}
