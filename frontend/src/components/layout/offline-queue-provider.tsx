"use client";

import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import { useSWRConfig } from "swr";
import { ApiError, isNetworkError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/auth-context";
import { createOrder } from "@/hooks/use-orders";
import { flushQueue, loadQueue, remove, retryRejected, type QueuedOrder, type SendResult } from "@/lib/offline-queue";

type Ctx = { queue: QueuedOrder[]; online: boolean; flushing: boolean; refresh: () => void; flushNow: () => void; discard: (key: string) => void; retry: (key: string) => void };
const C = createContext<Ctx>({ queue: [], online: true, flushing: false, refresh: () => {}, flushNow: () => {}, discard: () => {}, retry: () => {} });
export const useOfflineQueue = () => useContext(C);

async function send(o: QueuedOrder): Promise<SendResult> {
  try {
    await createOrder(o.payload, o.key);
    return { ok: true };
  } catch (e) {
    if (isNetworkError(e)) return { ok: false, kind: "network" };
    if (e instanceof ApiError) {
      if (e.status === 401) return { ok: false, kind: "auth" };
      if (e.status === 429 || e.status >= 500) return { ok: false, kind: "network" }; // transient: try again later
      return { ok: false, kind: "rejected", message: e.message };
    }
    return { ok: false, kind: "network" };
  }
}

export function OfflineQueueProvider({ children }: { children: React.ReactNode }) {
  const { user } = useAuth();
  const { mutate } = useSWRConfig();
  const [queue, setQueue] = useState<QueuedOrder[]>([]);
  const [online, setOnline] = useState(true);
  const [flushing, setFlushing] = useState(false);
  const busy = useRef(false);
  const branch = user?.activeBranch?.id ?? null;

  const refresh = useCallback(() => setQueue(loadQueue()), []);
  const flushNow = useCallback(async () => {
    if (busy.current || loadQueue().length === 0) return;
    busy.current = true;
    setFlushing(true);
    try {
      // navigator.locks stops two open tabs from replaying the same order at once (the server de-duplicates anyway).
      const run = () => flushQueue(branch, send);
      const r = await (navigator.locks ? navigator.locks.request("ct_offline_flush", run) : run());
      if (r.sent > 0) void mutate((k) => typeof k === "string" && (k.startsWith("/orders") || k.startsWith("/kitchen")));
    } finally {
      busy.current = false;
      setFlushing(false);
      refresh();
    }
  }, [branch, mutate, refresh]);

  useEffect(() => {
    refresh();
    setOnline(navigator.onLine);
    const on = () => { setOnline(true); void flushNow(); };
    const off = () => setOnline(false);
    const storage = (e: StorageEvent) => { if (e.key === "ct_offline_orders") refresh(); };
    window.addEventListener("online", on);
    window.addEventListener("offline", off);
    window.addEventListener("storage", storage);
    const timer = window.setInterval(() => void flushNow(), 20000);
    void flushNow();
    return () => { window.removeEventListener("online", on); window.removeEventListener("offline", off); window.removeEventListener("storage", storage); window.clearInterval(timer); };
  }, [flushNow, refresh]);

  const discard = (key: string) => { remove(key); refresh(); };
  const retry = (key: string) => { retryRejected(key); refresh(); void flushNow(); };

  return <C.Provider value={{ queue, online, flushing, refresh, flushNow, discard, retry }}>{children}</C.Provider>;
}

/** Thin bar under the header: offline notice, orders waiting to be sent, and rejected orders that need a person. */
export function OfflineBanner() {
  const { queue, online, flushing, flushNow, discard, retry } = useOfflineQueue();
  const waiting = queue.filter((q) => !q.error);
  const rejected = queue.filter((q) => q.error);
  if (online && queue.length === 0) return null;
  return (
    <div role="status" className="border-b border-warning/30 bg-warning/10 px-4 py-2 text-xs text-text-primary space-y-1">
      {!online && <p className="font-semibold">You are offline. New POS orders are saved on this device and sent when the connection returns. Payments, discounts, refunds and printing need a connection.</p>}
      {waiting.length > 0 && (
        <p>{waiting.length} order{waiting.length > 1 ? "s" : ""} waiting to be sent{flushing ? " — sending…" : ""}. <button className="underline" onClick={() => void flushNow()} disabled={!online || flushing}>Send now</button></p>
      )}
      {rejected.map((q) => (
        <p key={q.key} className="text-danger">
          Not accepted ({q.label}): {q.error} <button className="underline" onClick={() => retry(q.key)}>Retry</button>{" · "}
          <button className="underline" onClick={() => { if (window.confirm("Discard this order? It was never sent to the kitchen.")) discard(q.key); }}>Discard</button>
        </p>
      ))}
    </div>
  );
}
