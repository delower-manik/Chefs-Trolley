export const money = (n: number, locale: string) => `৳${new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(n)}`;

/** tel: link that works from a Bangladeshi mobile number in any common spelling; null when it doesn't look like a phone. */
export function telHref(raw: string | null): string | null {
  if (!raw) return null;
  const s = raw.replace(/[\s\-().]/g, "");
  return /^\+?\d{7,15}$/.test(s) ? `tel:${s}` : null;
}

/** Whole minutes since an ISO timestamp (never negative). */
export const minutesAgo = (iso: string | null, now = Date.now()): number | null => (iso ? Math.max(0, Math.floor((now - new Date(iso).getTime()) / 60000)) : null);

/**
 * Location is sent at most every `minMs`, and only if the phone moved noticeably or `maxMs` passed: saves battery and
 * data, and keeps the server's rate limit out of play.
 */
export function shouldSend(last: { lat: number; lng: number; at: number } | null, now: { lat: number; lng: number; at: number }, minMs = 15000, maxMs = 60000, minMeters = 25): boolean {
  if (!last) return true;
  const dt = now.at - last.at;
  if (dt < minMs) return false;
  if (dt >= maxMs) return true;
  return distanceMeters(last, now) >= minMeters;
}
export function distanceMeters(a: { lat: number; lng: number }, b: { lat: number; lng: number }): number {
  const R = 6371000, rad = Math.PI / 180;
  const dLat = (b.lat - a.lat) * rad, dLng = (b.lng - a.lng) * rad;
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(h));
}
