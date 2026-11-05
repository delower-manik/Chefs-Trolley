import type { CreateOrderPayload } from "@/types/order";

/**
 * Offline POS queue. Only NEW ORDERS are queued: they are re-sent with the SAME Idempotency-Key as the first attempt,
 * so the server turns a replay into "return the order that already exists" and never creates a duplicate.
 * Money is never queued — prices are recalculated by the server at replay time, and payments/refunds/discounts
 * need a live connection. State lives in localStorage (small, survives reloads); it is per device, not shared.
 */
export type QueuedOrder = {
  key: string; // Idempotency-Key, fixed at first attempt
  branchId: string; // the cashier's active branch when it was taken; replay only into that branch
  payload: CreateOrderPayload;
  label: string; // short human summary for the banner, e.g. "2× Burger, 1× Fries"
  createdAt: string;
  error?: string; // set when the server rejected it (needs a human)
};

const STORE = "ct_offline_orders";
const MAX = 50;

export function loadQueue(): QueuedOrder[] {
  try {
    const raw = window.localStorage.getItem(STORE);
    const v = raw ? JSON.parse(raw) : [];
    return Array.isArray(v) ? v : [];
  } catch {
    return [];
  }
}
function save(q: QueuedOrder[]): boolean {
  try {
    window.localStorage.setItem(STORE, JSON.stringify(q));
    return true;
  } catch {
    return false;
  }
}

/** Returns false when the order could NOT be stored (full or blocked storage): the caller must then keep the cart and tell the cashier. */
export function enqueue(o: QueuedOrder): boolean {
  const q = loadQueue();
  if (q.length >= MAX) return false;
  if (q.some((x) => x.key === o.key)) return true;
  return save([...q, o]);
}
export const remove = (key: string) => save(loadQueue().filter((x) => x.key !== key));
const setError = (key: string, error: string | undefined) => save(loadQueue().map((x) => (x.key === key ? { ...x, error } : x)));

export type SendResult = { ok: true } | { ok: false; kind: "network" | "auth" | "rejected"; message?: string };

/**
 * Replays queued orders oldest-first. Stops at the first network/auth problem (order matters to the kitchen and
 * there is no point hammering a dead link). A server rejection (e.g. an item was removed from the menu) is kept
 * with its message so a person decides — it is never silently dropped.
 */
export async function flushQueue(activeBranchId: string | null, send: (o: QueuedOrder) => Promise<SendResult>): Promise<{ sent: number; failed: number; blocked: boolean }> {
  let sent = 0;
  let failed = 0;
  for (const o of loadQueue()) {
    if (o.error) { failed++; continue; }
    if (!activeBranchId || o.branchId !== activeBranchId) continue; // wrong branch: leave it for the right cashier
    const r = await send(o);
    if (r.ok) { remove(o.key); sent++; continue; }
    if (r.kind === "rejected") { setError(o.key, r.message ?? "Rejected by the server."); failed++; continue; }
    return { sent, failed, blocked: true };
  }
  return { sent, failed, blocked: false };
}

/** Lets the cashier retry a rejected order after fixing the cause (clears the error so the next flush tries again). */
export const retryRejected = (key: string) => setError(key, undefined);

export const summarise = (items: { quantity: number; name: string }[]) => items.slice(0, 3).map((i) => `${i.quantity}× ${i.name}`).join(", ") + (items.length > 3 ? "…" : "");
