"use client";

import useSWR from "swr";
import { apiRequest } from "@/lib/api/client";
import type { PublicSettings, SettingsGrouped, SettingsValues } from "@/types/settings";

const get = <T,>(p: string) => apiRequest<T>(p);

export function usePublicSettings(enabled = true) {
  const { data, mutate } = useSWR<{ data: PublicSettings }>(enabled ? "/settings/public" : null, get);
  return { settings: data?.data, refresh: () => mutate() };
}

export function useSettings() {
  const { data, mutate, error } = useSWR<{ data: SettingsGrouped }>("/settings", get);
  return { values: data?.data, error, mutate };
}

export const saveSettings = (values: SettingsValues) => apiRequest<{ data: SettingsGrouped }>("/settings", { method: "PUT", body: values });
