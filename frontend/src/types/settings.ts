export type FeatureKey = "gift_cards" | "campaigns" | "reservations" | "wifi";

export type PublicSettings = {
  businessName: string;
  locale: "en" | "bn";
  timezone: string;
  currency: { code: string; symbol: string; decimals: number };
  features: Record<FeatureKey, boolean>;
};

/** GET /settings returns values grouped by prefix; PUT /settings takes flat dotted keys. */
export type SettingsGrouped = Record<string, Record<string, string | number | boolean | null>>;
export type SettingsValues = Record<string, string | number | boolean | null>;
