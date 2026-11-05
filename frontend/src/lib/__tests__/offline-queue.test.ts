import { beforeEach, describe, expect, it, vi } from "vitest";
import { enqueue, flushQueue, loadQueue, retryRejected, type QueuedOrder } from "@/lib/offline-queue";

const mk = (key: string, branchId = "b1"): QueuedOrder => ({ key, branchId, label: key, createdAt: "2026-01-01", payload: { source: "pos", items: [{ food_item_id: "i", quantity: 1 }] } as never });

beforeEach(() => {
  const m = new Map<string, string>();
  const localStorage = { getItem: (k: string) => m.get(k) ?? null, setItem: (k: string, v: string) => void m.set(k, v), removeItem: (k: string) => void m.delete(k) };
  vi.stubGlobal("window", { localStorage });
});

describe("offline queue", () => {
  it("stores once per key and keeps order", () => {
    enqueue(mk("a")); enqueue(mk("b")); enqueue(mk("a"));
    expect(loadQueue().map((q) => q.key)).toEqual(["a", "b"]);
  });
  it("refuses to grow past 50 so a dead device can't fill storage", () => {
    for (let i = 0; i < 50; i++) enqueue(mk(`k${i}`));
    expect(enqueue(mk("extra"))).toBe(false);
  });
  it("sends oldest first with the stored key, removing what succeeded", async () => {
    enqueue(mk("a")); enqueue(mk("b"));
    const seen: string[] = [];
    const r = await flushQueue("b1", async (o) => { seen.push(o.key); return { ok: true }; });
    expect(seen).toEqual(["a", "b"]);
    expect(r).toEqual({ sent: 2, failed: 0, blocked: false });
    expect(loadQueue()).toHaveLength(0);
  });
  it("stops at the first network failure and keeps the rest", async () => {
    enqueue(mk("a")); enqueue(mk("b"));
    const r = await flushQueue("b1", async () => ({ ok: false, kind: "network" }));
    expect(r.blocked).toBe(true);
    expect(loadQueue()).toHaveLength(2);
  });
  it("keeps a rejected order with its message, continues, and never re-sends it until retried", async () => {
    enqueue(mk("a")); enqueue(mk("b"));
    await flushQueue("b1", async (o) => (o.key === "a" ? { ok: false, kind: "rejected", message: "Item removed" } : { ok: true }));
    expect(loadQueue()).toMatchObject([{ key: "a", error: "Item removed" }]);
    let calls = 0;
    await flushQueue("b1", async () => { calls++; return { ok: true }; });
    expect(calls).toBe(0);
    retryRejected("a");
    await flushQueue("b1", async () => { calls++; return { ok: true }; });
    expect(calls).toBe(1);
  });
  it("never replays into a different branch", async () => {
    enqueue(mk("a", "b1"));
    let calls = 0;
    await flushQueue("b2", async () => { calls++; return { ok: true }; });
    await flushQueue(null, async () => { calls++; return { ok: true }; });
    expect(calls).toBe(0);
    expect(loadQueue()).toHaveLength(1);
  });
});
