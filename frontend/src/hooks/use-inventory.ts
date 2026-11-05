"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type {
  CreatePurchasePayload,
  CreateStockTransferPayload,
  Ingredient,
  Purchase,
  Recipe,
  SaveIngredientPayload,
  SaveRecipePayload,
  SaveSupplierPayload,
  SaveWarehousePayload,
  StockAdjustmentPayload,
  StockLedgerEntry,
  StockTransfer,
  Supplier,
  Unit,
  Warehouse,
} from "@/types/inventory";

type Paged<T> = { data: T[]; meta: { currentPage: number; perPage: number; total: number; lastPage: number } };

export function useUnits() {
  const { data, error, isLoading } = useSWR<{ data: Unit[] }>("/units", (p: string) => apiRequest<{ data: Unit[] }>(p));
  return { units: data?.data ?? [], isLoading, error };
}

export function useWarehouses() {
  const { data, error, isLoading, mutate } = useSWR<{ data: Warehouse[] }>("/warehouses", (p: string) =>
    apiRequest<{ data: Warehouse[] }>(p)
  );
  return { warehouses: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function createWarehouse(payload: SaveWarehousePayload) {
  return apiRequest<{ data: Warehouse }>("/warehouses", { method: "POST", body: payload });
}

export async function updateWarehouse(id: string, payload: Partial<SaveWarehousePayload>) {
  return apiRequest<{ data: Warehouse }>(`/warehouses/${id}`, { method: "PUT", body: payload });
}

export async function deleteWarehouse(id: string) {
  return apiRequest<void>(`/warehouses/${id}`, { method: "DELETE" });
}

export function useIngredients(search?: string, lowStockOnly?: boolean) {
  const params = new URLSearchParams();
  if (search) params.set("search", search);
  if (lowStockOnly) params.set("low_stock", "1");

  const { data, error, isLoading, mutate } = useSWR<Paged<Ingredient>>(
    `/ingredients?${params.toString()}`,
    (p: string) => apiRequest<Paged<Ingredient>>(p),
    { keepPreviousData: true }
  );

  return { ingredients: data?.data ?? [], meta: data?.meta, isLoading, error, refresh: mutate };
}

export async function createIngredient(payload: SaveIngredientPayload) {
  return apiRequest<{ data: Ingredient }>("/ingredients", { method: "POST", body: payload });
}

export async function updateIngredient(id: string, payload: Partial<SaveIngredientPayload>) {
  return apiRequest<{ data: Ingredient }>(`/ingredients/${id}`, { method: "PUT", body: payload });
}

export async function deleteIngredient(id: string) {
  return apiRequest<void>(`/ingredients/${id}`, { method: "DELETE" });
}

export function useSuppliers() {
  const { data, error, isLoading, mutate } = useSWR<{ data: Supplier[] }>("/suppliers", (p: string) =>
    apiRequest<{ data: Supplier[] }>(p)
  );
  return { suppliers: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function createSupplier(payload: SaveSupplierPayload) {
  return apiRequest<{ data: Supplier }>("/suppliers", { method: "POST", body: payload });
}

export async function deleteSupplier(id: string) {
  return apiRequest<void>(`/suppliers/${id}`, { method: "DELETE" });
}

export function usePurchases() {
  const { data, error, isLoading, mutate } = useSWR<Paged<Purchase>>("/purchases?per_page=20", (p: string) =>
    apiRequest<Paged<Purchase>>(p)
  );
  return { purchases: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function createPurchase(payload: CreatePurchasePayload) {
  return apiRequest<{ data: Purchase }>("/purchases", { method: "POST", body: payload });
}

export async function receivePurchase(id: string) {
  return apiRequest<{ data: Purchase }>(`/purchases/${id}/receive`, { method: "POST" });
}

export async function cancelPurchase(id: string) {
  return apiRequest<{ data: Purchase }>(`/purchases/${id}/cancel`, { method: "POST" });
}

export function useStockLedger(ingredientId?: string) {
  const params = new URLSearchParams();
  if (ingredientId) params.set("ingredient_id", ingredientId);

  const { data, error, isLoading, mutate } = useSWR<Paged<StockLedgerEntry>>(
    `/stock/ledger?${params.toString()}`,
    (p: string) => apiRequest<Paged<StockLedgerEntry>>(p)
  );
  return { entries: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function createStockAdjustment(payload: StockAdjustmentPayload) {
  return apiRequest<{ data: StockLedgerEntry }>("/stock/adjustments", { method: "POST", body: payload });
}

export function useStockTransfers() {
  const { data, error, isLoading, mutate } = useSWR<{ data: StockTransfer[] }>("/stock/transfers", (p: string) =>
    apiRequest<{ data: StockTransfer[] }>(p)
  );
  return { transfers: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function createStockTransfer(payload: CreateStockTransferPayload) {
  return apiRequest<{ data: StockTransfer }>("/stock/transfers", { method: "POST", body: payload });
}

export function useRecipe(foodItemId: string | null) {
  const { data, error, isLoading, mutate } = useSWR<{ data: Recipe }>(
    foodItemId ? `/food-items/${foodItemId}/recipe` : null,
    (p: string) => apiRequest<{ data: Recipe }>(p)
  );
  return { recipe: data?.data, isLoading, error, refresh: mutate };
}

export async function saveRecipe(foodItemId: string, payload: SaveRecipePayload) {
  return apiRequest<{ data: Recipe }>(`/food-items/${foodItemId}/recipe`, { method: "PUT", body: payload });
}
