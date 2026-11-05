/** Money helpers for the checkout UI. Display/convenience only — the server is the source of truth. */

const toMinor = (v: number) => Math.round(v * 100);
const toBdt = (m: number) => m / 100;

/** Splits a total into `ways` shares in whole paisa; the first shares absorb any leftover paisa. */
export function splitEqually(total: number, ways: number): number[] {
  const n = Math.max(1, Math.floor(ways));
  const minor = toMinor(total);
  const base = Math.floor(minor / n);
  const extra = minor - base * n;
  return Array.from({ length: n }, (_, i) => toBdt(base + (i < extra ? 1 : 0)));
}

/** Change to hand back for a cash payment (never negative). */
export function changeDue(amount: number, tip: number, tendered: number): number {
  return toBdt(Math.max(0, toMinor(tendered) - toMinor(amount) - toMinor(tip)));
}

/** Percentage-of-total discount preview, half-up in paisa (mirrors the server's Money::percent). */
export function percentOf(total: number, percent: number): number {
  return toBdt(Math.floor((toMinor(total) * Math.round(percent * 100) + 5000) / 10000));
}

/** Cash that should be in the drawer, for showing a live variance while counting. */
export function varianceOf(counted: number, expected: number): number {
  return toBdt(toMinor(counted) - toMinor(expected));
}

/** Browser-print an ESC/POS payload isn't possible; this decodes it for a local print agent download. */
export function base64ToBytes(b64: string): Uint8Array {
  const bin = atob(b64);
  return Uint8Array.from(bin, (c) => c.charCodeAt(0));
}
