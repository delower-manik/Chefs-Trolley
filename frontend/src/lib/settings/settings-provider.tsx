"use client";

import { createContext, useContext, useEffect, useMemo } from "react";
import { useAuth } from "@/lib/auth/auth-context";
import { usePublicSettings } from "@/hooks/use-settings";
import { setAppConfig } from "@/lib/app-config";
import { useI18n } from "@/i18n/i18n-provider";
import type { FeatureKey, PublicSettings } from "@/types/settings";

type Ctx = { settings?: PublicSettings; featureOn: (f?: FeatureKey) => boolean; refresh: () => void };
const SettingsContext = createContext<Ctx>({ featureOn: () => true, refresh: () => {} });

/** Loads the business settings once signed in, pushes them into the formatters, and supplies the default language. */
export function SettingsProvider({ children }: { children: React.ReactNode }) {
  const { status } = useAuth();
  const { settings, refresh } = usePublicSettings(status === "authenticated");
  const { setDefaultLocale } = useI18n();

  useEffect(() => {
    if (!settings) return;
    setAppConfig({ symbol: settings.currency.symbol, decimals: settings.currency.decimals, timezone: settings.timezone });
  }, [settings]);

  useEffect(() => {
    if (settings) setDefaultLocale(settings.locale);
  }, [settings, setDefaultLocale]);

  const value = useMemo<Ctx>(() => ({ settings, refresh, featureOn: (f) => !f || settings?.features[f] !== false }), [settings, refresh]);
  return <SettingsContext.Provider value={value}>{children}</SettingsContext.Provider>;
}

export const useSettingsContext = () => useContext(SettingsContext);
