"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type { Category, FoodItem, SaveCategoryPayload, SaveFoodItemPayload } from "@/types/menu";

// Re-declared here to avoid a circular import; matches PaginatedResponse<T> shape.
type Paged<T> = { data: T[]; meta: { currentPage: number; perPage: number; total: number; lastPage: number } };

export function useCategories() {
  const { data, error, isLoading, mutate } = useSWR<{ data: Category[] }>("/categories", (p: string) => apiRequest<{ data: Category[] }>(p));

  return { categories: data?.data ?? [], isLoading, error, refresh: mutate };
}

export async function createCategory(payload: SaveCategoryPayload) {
  return apiRequest<{ data: Category }>("/categories", { method: "POST", body: payload });
}

export async function updateCategory(id: string, payload: SaveCategoryPayload) {
  return apiRequest<{ data: Category }>(`/categories/${id}`, { method: "PUT", body: payload });
}

export async function deleteCategory(id: string) {
  return apiRequest<void>(`/categories/${id}`, { method: "DELETE" });
}

export function useFoodItems(categoryId?: string, search?: string) {
  const params = new URLSearchParams();
  if (categoryId) params.set("category_id", categoryId);
  if (search) params.set("search", search);

  const { data, error, isLoading, mutate } = useSWR<Paged<FoodItem>>(
    `/food-items?${params.toString()}`,
    (p: string) => apiRequest<Paged<FoodItem>>(p),
    { keepPreviousData: true }
  );

  return { items: data?.data ?? [], meta: data?.meta, isLoading, error, refresh: mutate };
}

export async function createFoodItem(payload: SaveFoodItemPayload) {
  return apiRequest<{ data: FoodItem }>("/food-items", { method: "POST", body: payload });
}

export async function updateFoodItem(id: string, payload: SaveFoodItemPayload) {
  return apiRequest<{ data: FoodItem }>(`/food-items/${id}`, { method: "PUT", body: payload });
}

export async function deleteFoodItem(id: string) {
  return apiRequest<void>(`/food-items/${id}`, { method: "DELETE" });
}
