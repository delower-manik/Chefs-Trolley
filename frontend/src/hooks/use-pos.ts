"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type {
  CashShift,
  DiscountPayload,
  Payment,
  PaymentsResponse,
  PrintResult,
  Printer,
  ReceiptDoc,
  RefundPayload,
  SavePrinterPayload,
  TakePaymentPayload,
} from "@/types/pos";

export function useOrderPayments(orderId: string | undefined) {
  const { data, error, isLoading, mutate } = useSWR<PaymentsResponse>(orderId ? `/orders/${orderId}/payments` : null, (p: string) =>
    apiRequest<PaymentsResponse>(p)
  );
  return { payments: data?.data ?? [], summary: data?.summary, isLoading, error, refresh: mutate };
}

export const takePayment = (orderId: string, body: TakePaymentPayload) =>
  apiRequest<{ data: Payment }>(`/orders/${orderId}/payments`, { method: "POST", body });
export const refundOrder = (orderId: string, body: RefundPayload) =>
  apiRequest<{ data: Payment }>(`/orders/${orderId}/refunds`, { method: "POST", body });
export const voidPayment = (paymentId: string, reason: string) =>
  apiRequest<{ data: Payment }>(`/payments/${paymentId}/void`, { method: "POST", body: { reason } });
export const applyDiscount = (orderId: string, body: DiscountPayload) =>
  apiRequest<{ data: { grandTotal: number } }>(`/orders/${orderId}/discount`, { method: "PUT", body });
export const removeDiscount = (orderId: string) => apiRequest<{ data: { grandTotal: number } }>(`/orders/${orderId}/discount`, { method: "DELETE" });

// ---- Cash register ----
export function useCurrentShift() {
  const { data, error, isLoading, mutate } = useSWR<{ data: CashShift | null }>("/cash/shifts/current", (p: string) =>
    apiRequest<{ data: CashShift | null }>(p)
  );
  return { shift: data?.data ?? null, isLoading, error, refresh: mutate };
}
export function useShifts() {
  const { data, error, isLoading, mutate } = useSWR<{ data: CashShift[] }>("/cash/shifts", (p: string) => apiRequest<{ data: CashShift[] }>(p));
  return { shifts: data?.data ?? [], isLoading, error, refresh: mutate };
}
export const openShift = (opening_float: number) => apiRequest<{ data: CashShift }>("/cash/shifts/open", { method: "POST", body: { opening_float } });
export const cashMovement = (id: string, body: { type: "in" | "out"; amount: number; reason: string }) =>
  apiRequest<{ data: CashShift }>(`/cash/shifts/${id}/movements`, { method: "POST", body });
export const closeShift = (id: string, body: { counted_cash: number; notes?: string }) =>
  apiRequest<{ data: CashShift }>(`/cash/shifts/${id}/close`, { method: "POST", body });
export const getShiftReport = (id: string) => apiRequest<{ data: CashShift }>(`/cash/shifts/${id}/report`);
export const openDrawer = () => apiRequest<{ data: { escposBase64: string } }>("/cash/drawer/open", { method: "POST" });

// ---- Printers & receipts ----
export function usePrinters() {
  const { data, error, isLoading, mutate } = useSWR<{ data: Printer[] }>("/printers", (p: string) => apiRequest<{ data: Printer[] }>(p));
  return { printers: data?.data ?? [], isLoading, error, refresh: mutate };
}
export const createPrinter = (body: SavePrinterPayload) => apiRequest<{ data: Printer }>("/printers", { method: "POST", body });
export const updatePrinter = (id: string, body: SavePrinterPayload) => apiRequest<{ data: Printer }>(`/printers/${id}`, { method: "PUT", body });
export const deletePrinter = (id: string) => apiRequest<void>(`/printers/${id}`, { method: "DELETE" });
export const fetchReceipt = (orderId: string) => apiRequest<{ data: ReceiptDoc }>(`/orders/${orderId}/receipt`);
export const printOrder = (orderId: string, body: { printer_id: string; type: "receipt" | "kitchen"; copy?: boolean }) =>
  apiRequest<{ data: PrintResult }>(`/orders/${orderId}/print`, { method: "POST", body });
