"use client";

import { useState } from "react";
import { usePathname } from "next/navigation";
import { useAuth } from "@/lib/auth/auth-context";
import { Sidebar } from "@/components/layout/sidebar";
import { Header } from "@/components/layout/header";
import { RealtimeProvider } from "@/components/layout/realtime-provider";
import { OfflineBanner, OfflineQueueProvider } from "@/components/layout/offline-queue-provider";
import { ALL_NAV_ITEMS } from "@/config/navigation";
import { useI18n } from "@/i18n/i18n-provider";
import type { MessageKey } from "@/i18n/en";

function resolveKey(pathname: string): MessageKey | null {
  const match = ALL_NAV_ITEMS.find((item) => pathname === item.href || pathname.startsWith(`${item.href}/`));
  return match ? (`nav.${match.id}` as MessageKey) : null;
}

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const { status } = useAuth();
  const pathname = usePathname();
  const { t } = useI18n();
  const [menuOpen, setMenuOpen] = useState(false);
  const titleKey = resolveKey(pathname);

  if (status === "loading") {
    return (
      <div className="h-screen w-full grid place-items-center bg-bg">
        <div className="flex items-center gap-3 text-text-secondary">
          <span className="w-5 h-5 border-2 border-primary border-t-transparent rounded-full animate-spin" />
          Loading session...
        </div>
      </div>
    );
  }

  // Middleware already redirects unauthenticated visitors; this is a defensive
  // second layer so the shell never flashes protected content.
  if (status === "unauthenticated") return null;

  return (
    <RealtimeProvider>
    <OfflineQueueProvider>
    <div className="flex h-screen w-full bg-bg">
      <Sidebar open={menuOpen} onClose={() => setMenuOpen(false)} />
      <main className="flex-1 min-w-0 md:ml-sidebar flex flex-col h-screen overflow-hidden">
        <Header title={titleKey ? t(titleKey) : "Chefs Trolley"} subtitle="Chefs Trolley Management Platform" onMenu={() => setMenuOpen(true)} />
        <OfflineBanner />
        <div className="flex-1 overflow-y-auto p-4 md:p-6">{children}</div>
      </main>
    </div>
    </OfflineQueueProvider>
    </RealtimeProvider>
  );
}
