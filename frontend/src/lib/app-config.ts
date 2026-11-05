// Process-wide display settings (money, locale, timezone). Set once by SettingsProvider after sign-in; read by the
// plain formatting helpers so none of the 37 call sites need to change.
export type AppConfig = { symbol: string; decimals: number; locale: "en" | "bn"; timezone: string };

let config: AppConfig = { symbol: "৳", decimals: 0, locale: "en", timezone: "Asia/Dhaka" };

export const getAppConfig = (): AppConfig => config;
export const setAppConfig = (next: Partial<AppConfig>): void => {
  config = { ...config, ...next };
};
