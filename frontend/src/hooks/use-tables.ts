"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type { DiningTable, Reservation, SaveReservationPayload, SaveTablePayload } from "@/types/table";

type Paged<T> = { data: T[]; meta: { currentPage: number; perPage: number; total: number; lastPage: number } };

export function useTables() {
  const { data, error, isLoading, mutate } = useSWR<{ data: DiningTable[] }>("/tables", (p: string) => apiRequest<{ data: DiningTable[] }>(p));
  return { tables: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function createTable(payload: SaveTablePayload) {
  return apiRequest<{ data: DiningTable }>("/tables", { method: "POST", body: payload });
}

export async function updateTable(id: string, payload: Partial<SaveTablePayload>) {
  return apiRequest<{ data: DiningTable }>(`/tables/${id}`, { method: "PUT", body: payload });
}

export async function deleteTable(id: string) {
  return apiRequest<void>(`/tables/${id}`, { method: "DELETE" });
}

export function useReservations(date?: string) {
  const params = new URLSearchParams();
  if (date) params.set("date", date);

  const { data, error, isLoading, mutate } = useSWR<Paged<Reservation>>(
    `/reservations?${params.toString()}`,
    (p: string) => apiRequest<Paged<Reservation>>(p),
    { keepPreviousData: true }
  );

  return { reservations: data?.data ?? [], meta: data?.meta, isLoading, error, refresh: mutate };
}

export async function createReservation(payload: SaveReservationPayload) {
  return apiRequest<{ data: Reservation }>("/reservations", { method: "POST", body: payload });
}

export async function cancelReservation(id: string) {
  return apiRequest<{ data: Reservation }>(`/reservations/${id}/cancel`, { method: "POST" });
}
