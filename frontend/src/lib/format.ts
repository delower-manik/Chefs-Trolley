import { getAppConfig } from "@/lib/app-config";

/** Intl locale for the active UI language. Bangla uses Bengali digits (০-৯). */
export const intlLocale = (): string => (getAppConfig().locale === "bn" ? "bn-BD" : "en-BD");

/** Money from the API is always BDT; the symbol and number of decimals shown are business settings. */
export function formatBDT(amount: number): string {
  const { symbol, decimals } = getAppConfig();
  const num = new Intl.NumberFormat(intlLocale(), { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(amount);
  return `${symbol}${num}`;
}

export function formatNumber(n: number): string {
  return new Intl.NumberFormat(intlLocale()).format(n);
}

export function formatDateTime(iso: string, timeZone = getAppConfig().timezone): string {
  return new Intl.DateTimeFormat(intlLocale(), {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone,
  }).format(new Date(iso));
}

const DHAKA = "Asia/Dhaka";

/** Today's calendar date (YYYY-MM-DD) in Dhaka -- not the browser's UTC date, which is a day behind for six hours every night. */
export function todayInDhaka(now: Date = new Date()): string {
  // The "en-CA" locale formats dates as YYYY-MM-DD.
  return new Intl.DateTimeFormat("en-CA", { timeZone: DHAKA }).format(now);
}

export function startOfMonthInDhaka(now: Date = new Date()): string {
  return `${todayInDhaka(now).slice(0, 7)}-01`;
}

/** Formats a plain calendar date (YYYY-MM-DD) without any timezone shifting. */
export function formatDate(isoDate: string): string {
  return new Intl.DateTimeFormat(intlLocale(), { dateStyle: "medium", timeZone: "UTC" }).format(
    new Date(`${isoDate}T00:00:00Z`)
  );
}

