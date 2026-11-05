"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";

export type DashboardMetrics = {
  todaySales: number | null;
  ordersToday: number;
  openKitchenOrders: number;
  stockAlerts: number;
  salesByHour: { hour: string; amount: number }[];
  recentOrders: { code: string; type: string; total: number | null }[];
};

export function useDashboardMetrics() {
  const { data, error, isLoading } = useSWR<{ data: DashboardMetrics }>(
    "/dashboard/metrics",
    (path: string) => apiRequest(path),
    { refreshInterval: 30_000 }
  );

  return {
    metrics: data?.data,
    isLoading,
    error,
  };
}
