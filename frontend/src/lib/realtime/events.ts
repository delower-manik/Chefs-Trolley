/**
 * Which cached API responses go stale when a real-time event arrives.
 * Keys are the SWR keys (API paths such as "/orders?page=1"). Matching is by path prefix, never by substring,
 * so "/orders" refreshes "/orders?x" and "/orders/abc" but not "/orders-archive".
 */
const RULES: Record<string, string[]> = {
  "order.changed": ["/orders", "/kitchen/tickets", "/pos/catalog", "/tables", "/floor-plans", "/dashboard", "/delivery"],
  "delivery.changed": ["/delivery", "/orders"],
};

export function pathOf(key: string): string {
  const q = key.indexOf("?");
  return q === -1 ? key : key.slice(0, q);
}

export function shouldRevalidate(event: string, key: unknown): boolean {
  if (typeof key !== "string") return false;
  const path = pathOf(key);
  return (RULES[event] ?? []).some((prefix) => path === prefix || path.startsWith(`${prefix}/`));
}

export type RealtimeStatus = "off" | "connecting" | "live" | "offline";
