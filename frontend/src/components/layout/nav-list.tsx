"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import clsx from "clsx";
import { NAVIGATION } from "@/config/navigation";
import { useAuth } from "@/lib/auth/auth-context";
import { useT } from "@/i18n/i18n-provider";
import { useSettingsContext } from "@/lib/settings/settings-provider";
import type { MessageKey } from "@/i18n/en";

/** The primary navigation, shared by the desktop sidebar and the mobile drawer. */
export function NavList({ onNavigate }: { onNavigate?: () => void }) {
  const pathname = usePathname();
  const { hasPermission } = useAuth();
  const { featureOn } = useSettingsContext();
  const t = useT();

  return (
    <nav className="flex-1 overflow-y-auto px-2 pb-6" aria-label="Primary">
      {NAVIGATION.map((group) => {
        const visibleItems = group.items.filter((item) => (!item.permission || hasPermission(item.permission)) && featureOn(item.feature));
        if (visibleItems.length === 0) return null;
        return (
          <div key={group.group}>
            <div className="px-3 pt-4 pb-2 text-[10px] font-bold uppercase tracking-widest text-text-muted">{t(`nav.group.${group.group}` as MessageKey)}</div>
            {visibleItems.map((item) => {
              const isActive = pathname === item.href || pathname.startsWith(`${item.href}/`);
              const label = t(`nav.${item.id}` as MessageKey);
              if (!item.implemented) {
                return (
                  <div key={item.id} className="w-full flex items-center justify-between gap-3 px-3 py-2.5 rounded-lg text-sm text-text-muted cursor-not-allowed" title={`Scheduled for Phase ${item.phase}`}>
                    <span className="flex items-center gap-3">
                      <span className="material-symbols-outlined text-[20px]">{item.icon}</span>
                      <span>{label}</span>
                    </span>
                    <span className="text-[10px] border border-border rounded px-1.5 py-0.5">P{item.phase}</span>
                  </div>
                );
              }
              return (
                <Link
                  key={item.id}
                  href={item.href as never}
                  onClick={onNavigate}
                  aria-current={isActive ? "page" : undefined}
                  className={clsx(
                    "w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary",
                    isActive ? "text-strong bg-surface-hover border-l-4 border-primary" : "text-text-secondary hover:bg-surface-hover hover:text-strong"
                  )}
                >
                  <span className="material-symbols-outlined text-[20px]" aria-hidden>{item.icon}</span>
                  <span>{label}</span>
                </Link>
              );
            })}
          </div>
        );
      })}
    </nav>
  );
}
