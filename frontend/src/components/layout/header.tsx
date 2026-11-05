"use client";

import { useState } from "react";
import { useAuth } from "@/lib/auth/auth-context";
import Link from "next/link";
import { useRealtimeStatus } from "@/components/layout/realtime-provider";
import { useI18n } from "@/i18n/i18n-provider";
import { useTheme, type ThemePreference } from "@/lib/theme/theme-provider";

export function Header({ title, subtitle, onMenu }: { title: string; subtitle?: string; onMenu?: () => void }) {
  const { user, logout } = useAuth();
  const [menuOpen, setMenuOpen] = useState(false);
  const live = useRealtimeStatus();
  const { t, locale, setLocale } = useI18n();
  const { preference, setPreference } = useTheme();

  return (
    <header className="h-header shrink-0 flex items-center justify-between px-3 sm:px-5 gap-2 bg-surface-raised/90 backdrop-blur border-b border-border">
      <div className="flex items-center gap-2 min-w-0">
        <button onClick={onMenu} className="md:hidden w-10 h-10 shrink-0 rounded-full hover:bg-surface-hover grid place-items-center" aria-label={t("header.menu")}>
          <span className="material-symbols-outlined text-text-secondary" aria-hidden>menu</span>
        </button>
        <div className="min-w-0">
          <h1 className="text-lg sm:text-xl font-bold text-strong truncate">{title}</h1>
          {subtitle && <p className="hidden sm:block text-xs text-text-secondary truncate">{subtitle}</p>}
        </div>
      </div>

      <div className="flex items-center gap-2">
        {live !== "off" && (
          <span
            className="hidden sm:flex items-center gap-1.5 text-xs text-text-secondary mr-1"
            title={live === "live" ? "Live updates are on" : live === "connecting" ? "Connecting to live updates" : "Live updates are offline — the page still refreshes normally"}
          >
            <span className={`w-2 h-2 rounded-full ${live === "live" ? "bg-success" : live === "connecting" ? "bg-warning animate-pulse" : "bg-danger"}`} aria-hidden />
            {live === "live" ? t("header.live") : live === "connecting" ? t("header.connecting") : t("header.offline")}
          </span>
        )}
        <div className="hidden lg:flex items-center bg-surface rounded-lg px-3 border border-border">
          <span className="material-symbols-outlined text-text-muted text-[20px]">search</span>
          <input
            className="bg-transparent border-0 focus:ring-0 w-56 text-sm py-2 px-2 text-text-primary placeholder:text-text-muted outline-none"
            placeholder={t("header.searchPlaceholder")}
            aria-label="Global search"
          />
        </div>

        <button
          className="w-10 h-10 rounded-full hover:bg-surface-hover relative grid place-items-center"
          aria-label={t("header.notifications")}
        >
          <span className="material-symbols-outlined text-text-secondary">notifications</span>
          <span className="absolute top-2 right-2 w-2 h-2 bg-warning rounded-full" aria-hidden />
        </button>

        <div className="relative">
          <button
            onClick={() => setMenuOpen((v) => !v)}
            className="flex items-center gap-2 sm:ml-2 rounded-lg px-2 py-1.5 hover:bg-surface-hover"
            aria-haspopup="menu"
            aria-expanded={menuOpen}
          >
            <div className="w-9 h-9 rounded-full bg-primary/15 text-primary-fg grid place-items-center font-bold text-sm">
              {user?.name?.slice(0, 2).toUpperCase() ?? "CT"}
            </div>
            <div className="hidden sm:block text-left">
              <b className="text-sm text-strong block leading-tight">{user?.name ?? "Loading..."}</b>
              <div className="text-xs text-text-secondary">{user?.roles[0]?.name ?? ""}</div>
            </div>
          </button>

          {menuOpen && (
            <div
              role="menu"
              className="absolute right-0 mt-2 w-56 card p-1 z-50"
            >
              <Link href="/account/security" role="menuitem" onClick={() => setMenuOpen(false)} className="block px-3 py-2 text-sm rounded-lg hover:bg-surface-hover text-text-primary">
                {t("header.mySecurity")}
              </Link>
              <div className="px-3 pt-2 pb-1 text-[10px] uppercase tracking-widest text-text-muted">{t("header.theme")}</div>
              <div className="flex gap-1 px-2 pb-2" role="group" aria-label={t("header.theme")}>
                {(["system", "light", "dark"] as ThemePreference[]).map((p) => (
                  <button key={p} onClick={() => setPreference(p)} aria-pressed={preference === p}
                    className={`flex-1 text-xs rounded-lg py-1.5 border ${preference === p ? "border-primary text-primary-fg bg-primary/10" : "border-border text-text-secondary hover:bg-surface-hover"}`}>
                    {t(`theme.${p}` as const)}
                  </button>
                ))}
              </div>
              <div className="px-3 pt-1 pb-1 text-[10px] uppercase tracking-widest text-text-muted">{t("header.language")}</div>
              <div className="flex gap-1 px-2 pb-2" role="group" aria-label={t("header.language")}>
                {(["en", "bn"] as const).map((l) => (
                  <button key={l} onClick={() => setLocale(l)} aria-pressed={locale === l}
                    className={`flex-1 text-xs rounded-lg py-1.5 border ${locale === l ? "border-primary text-primary-fg bg-primary/10" : "border-border text-text-secondary hover:bg-surface-hover"}`}>
                    {l === "en" ? "English" : "বাংলা"}
                  </button>
                ))}
              </div>
              <button role="menuitem" onClick={logout} className="w-full text-left px-3 py-2 text-sm rounded-lg hover:bg-surface-hover text-danger">
                {t("common.signOut")}
              </button>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}
