export type Money = { symbol: string; decimals: number; locale: string };

export function formatMoney(amount: number, m: Money): string {
  return `${m.symbol}${new Intl.NumberFormat(m.locale, { minimumFractionDigits: m.decimals, maximumFractionDigits: m.decimals }).format(amount)}`;
}

/** Only a strict #RRGGBB reaches CSS: the value comes from an admin-editable setting and is written into a style attribute. */
export function safeColor(c: string | null | undefined): string | null {
  return c && /^#[0-9a-fA-F]{6}$/.test(c) ? c : null;
}

export function hexToRgbChannels(hex: string): string {
  const n = parseInt(hex.slice(1), 16);
  return `${(n >> 16) & 255} ${(n >> 8) & 255} ${n & 255}`;
}

/** Same rule as the API: 01XXXXXXXXX, 8801…, +8801…; returns null when it isn't a Bangladeshi mobile number. */
export function normalizePhone(raw: string): string | null {
  let s = raw.trim().replace(/[০-৯]/g, (d) => String("০১২৩৪৫৬৭৮৯".indexOf(d))).replace(/[\s\-().]/g, "");
  if (s.startsWith("+880")) s = "0" + s.slice(4);
  else if (s.startsWith("880")) s = "0" + s.slice(3);
  return /^01[3-9]\d{8}$/.test(s) ? s : null;
}

/** Cart line identity: same dish with the same extras and note merges into one line. */
export function lineKey(id: string, modifierIds: string[], notes?: string): string {
  return [id, [...modifierIds].sort().join(","), (notes ?? "").trim().toLowerCase()].join("|");
}
