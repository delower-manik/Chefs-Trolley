"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type { Order } from "@/types/order";
import type {
  Combo,
  FloorPlan,
  KitchenStation,
  LayoutEntry,
  OrderLineInput,
  PosCatalog,
  SaveComboPayload,
  SaveFloorPlanPayload,
  SaveStationPayload,
  SaveTaxRatePayload,
  TaxRate,
} from "@/types/restaurant";

function useList<T>(path: string | null) {
  const { data, error, isLoading, mutate } = useSWR<{ data: T[] }>(path, (p: string) => apiRequest<{ data: T[] }>(p));
  return { items: data?.data ?? [], isLoading, error, refresh: mutate };
}

// ---- Tax rates ----
export const useTaxRates = () => useList<TaxRate>("/tax-rates");
export const createTaxRate = (body: SaveTaxRatePayload) => apiRequest<{ data: TaxRate }>("/tax-rates", { method: "POST", body });
export const updateTaxRate = (id: string, body: SaveTaxRatePayload) => apiRequest<{ data: TaxRate }>(`/tax-rates/${id}`, { method: "PUT", body });
export const deleteTaxRate = (id: string) => apiRequest<void>(`/tax-rates/${id}`, { method: "DELETE" });

// ---- Kitchen stations ----
export const useStations = () => useList<KitchenStation>("/kitchen/stations");
export const createStation = (body: SaveStationPayload) => apiRequest<{ data: KitchenStation }>("/kitchen/stations", { method: "POST", body });
export const updateStation = (id: string, body: SaveStationPayload) =>
  apiRequest<{ data: KitchenStation }>(`/kitchen/stations/${id}`, { method: "PUT", body });
export const deleteStation = (id: string) => apiRequest<void>(`/kitchen/stations/${id}`, { method: "DELETE" });

// ---- Floor plans ----
export const useFloorPlans = () => useList<FloorPlan>("/floor-plans");
export const createFloorPlan = (body: SaveFloorPlanPayload) => apiRequest<{ data: FloorPlan }>("/floor-plans", { method: "POST", body });
export const updateFloorPlan = (id: string, body: SaveFloorPlanPayload) =>
  apiRequest<{ data: FloorPlan }>(`/floor-plans/${id}`, { method: "PUT", body });
export const deleteFloorPlan = (id: string) => apiRequest<void>(`/floor-plans/${id}`, { method: "DELETE" });
export const saveFloorPlanLayout = (id: string, tables: LayoutEntry[]) =>
  apiRequest<{ data: FloorPlan }>(`/floor-plans/${id}/layout`, { method: "PUT", body: { tables } });

// ---- Combos ----
export const useCombos = () => useList<Combo>("/combos");
export const createCombo = (body: SaveComboPayload) => apiRequest<{ data: Combo }>("/combos", { method: "POST", body });
export const updateCombo = (id: string, body: SaveComboPayload) => apiRequest<{ data: Combo }>(`/combos/${id}`, { method: "PUT", body });
export const deleteCombo = (id: string) => apiRequest<void>(`/combos/${id}`, { method: "DELETE" });

// ---- Order-taking (POS / waiter) ----
export function usePosCatalog() {
  const { data, error, isLoading, mutate } = useSWR<{ data: PosCatalog }>("/pos/catalog", (p: string) => apiRequest<{ data: PosCatalog }>(p));
  return { catalog: data?.data, isLoading, error, refresh: mutate };
}

export const addOrderItems = (orderId: string, items: OrderLineInput[]) =>
  apiRequest<{ data: Order }>(`/orders/${orderId}/items`, { method: "POST", body: { items } });

export const transferOrderTable = (orderId: string, diningTableId: string) =>
  apiRequest<{ data: Order }>(`/orders/${orderId}/transfer-table`, { method: "POST", body: { dining_table_id: diningTableId } });
