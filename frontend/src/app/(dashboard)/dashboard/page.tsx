"use client";

import Link from "next/link";
import { useDashboardMetrics } from "@/hooks/use-dashboard-metrics";
import { KpiCard, KpiCardSkeleton } from "@/components/ui/kpi-card";
import { formatBDT } from "@/lib/format";

export default function DashboardPage() {
  const { metrics, isLoading, error } = useDashboardMetrics();

  if (error) {
    return (
      <div className="card p-8 text-center max-w-md mx-auto mt-12">
        <span className="material-symbols-outlined text-danger text-4xl">error</span>
        <h2 className="font-bold text-strong mt-3">Couldn&apos;t load dashboard metrics</h2>
        <p className="text-sm text-text-secondary mt-1">
          The API didn&apos;t respond. Check that the backend is running and reachable.
        </p>
      </div>
    );
  }

  return (
    <section className="max-w-7xl mx-auto space-y-6">
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {isLoading || !metrics ? (
          Array.from({ length: 4 }).map((_, i) => <KpiCardSkeleton key={i} />)
        ) : (
          <>
            <KpiCard label="Today's Sales" value={metrics.todaySales === null ? "—" : formatBDT(metrics.todaySales)} icon="trending_up" />
            <KpiCard label="Orders Today" value={String(metrics.ordersToday)} icon="receipt_long" />
            <KpiCard label="Open Kitchen Orders" value={String(metrics.openKitchenOrders)} icon="soup_kitchen" />
            <KpiCard label="Stock Alerts" value={String(metrics.stockAlerts)} icon="inventory_2" />
          </>
        )}
      </div>

      <div className="card overflow-hidden">
        <div className="p-5 border-b border-border flex justify-between items-center">
          <h3 className="font-bold text-strong">Recent Orders</h3>
          <Link href="/orders" className="text-primary-fg text-sm font-medium">
            View all
          </Link>
        </div>

        {isLoading || !metrics ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : metrics.recentOrders.length === 0 ? (
          <div className="p-10 text-center text-text-secondary text-sm">
            No orders yet today. New orders will appear here as they come in.
          </div>
        ) : (
          metrics.recentOrders.map((o) => (
            <div key={o.code} className="p-4 border-b border-border text-sm last:border-b-0 flex justify-between">
              <span>
                <b className="text-strong">{o.code}</b> · {o.type}
              </span>
              <span className="text-text-secondary">{o.total === null ? "—" : formatBDT(o.total)}</span>
            </div>
          ))
        )}
      </div>
    </section>
  );
}
