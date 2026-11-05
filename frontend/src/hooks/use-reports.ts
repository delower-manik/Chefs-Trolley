"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type {
  BranchesReport,
  CategoriesReport,
  CustomersReport,
  DeliveryReport,
  ProductsReport,
  SalesReport,
} from "@/types/report";

/**
 * One shared SWR wrapper for every report. Passing `enabled: false` (e.g. an
 * invalid date range) skips the request entirely instead of firing a call the
 * server is going to reject.
 */
function useReport<T>(name: string, from: string, to: string, enabled = true) {
  const path = `/reports/${name}?from=${from}&to=${to}`;
  const { data, error, isLoading } = useSWR<{ data: T }>(enabled ? path : null, (p: string) =>
    apiRequest<{ data: T }>(p)
  );
  return { report: data?.data, isLoading, error };
}

export const useSalesReport = (from: string, to: string, enabled = true) =>
  useReport<SalesReport>("sales", from, to, enabled);
export const useProductsReport = (from: string, to: string, enabled = true) =>
  useReport<ProductsReport>("products", from, to, enabled);
export const useCategoriesReport = (from: string, to: string, enabled = true) =>
  useReport<CategoriesReport>("categories", from, to, enabled);
export const useDeliveryReport = (from: string, to: string, enabled = true) =>
  useReport<DeliveryReport>("delivery", from, to, enabled);
export const useCustomersReport = (from: string, to: string, enabled = true) =>
  useReport<CustomersReport>("customers", from, to, enabled);
export const useBranchesReport = (from: string, to: string, enabled = true) =>
  useReport<BranchesReport>("branches", from, to, enabled);

/**
 * Downloads the sales report as CSV. Goes through apiRequest so it gets the
 * same auth header, token refresh and error handling as every other call,
 * then hands the blob to the browser via a temporary link.
 */
export async function downloadSalesCsv(from: string, to: string): Promise<void> {
  const blob = await apiRequest<Blob>(`/reports/sales/export?from=${from}&to=${to}`, { parseAs: "blob" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `sales-${from}-to-${to}.csv`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
