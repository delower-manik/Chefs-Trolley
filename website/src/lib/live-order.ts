import { API } from "./config";
import { getToken } from "./client-api";

type Config = { driver: "none" } | { driver: "pusher"; key: string; cluster: string; wsHost?: string; wsPort?: number; forceTLS?: boolean };

/** How long to wait before the next status check. Pure so it can be tested. */
export function nextDelay(o: { pushLive: boolean; hidden: boolean; failures: number; ageMs: number }): number {
  if (o.failures > 0) return Math.min(60000, 5000 * 2 ** Math.min(o.failures, 4)); // back off on errors: 10s, 20s, 40s, 60s
  if (o.pushLive) return 60000; // push is the fast path; this is only a safety net
  if (o.hidden) return 30000; // background tab
  return o.ageMs < 10 * 60 * 1000 ? 5000 : 15000; // lively for the first 10 minutes, calmer afterwards
}

/**
 * Keeps an order page fresh. Uses a push channel when the server offers one (Pusher/Reverb) and otherwise polls a tiny
 * endpoint that answers 304 when nothing changed. Either way `onChange` just means "reload the order through the normal API":
 * the push message itself carries only the stage. Returns stop().
 */
export function followOrder(orderId: string, onChange: () => void): () => void {
  let stopped = false;
  let timer: number | undefined;
  let etag: string | null = null;
  let failures = 0;
  let pushLive = false;
  let disconnect: (() => void) | undefined;
  const started = Date.now();

  const tick = async () => {
    if (stopped) return;
    try {
      const res = await fetch(`${API}/web/orders/${encodeURIComponent(orderId)}/status`, {
        headers: { Accept: "application/json", Authorization: `Bearer ${getToken() ?? ""}`, ...(etag ? { "If-None-Match": etag } : {}) },
      });
      if (res.status === 304) failures = 0;
      else if (res.ok) {
        failures = 0;
        const first = etag === null;
        etag = res.headers.get("ETag");
        if (!first) onChange(); // the first answer is the state the page already loaded
      } else failures++;
    } catch {
      failures++;
    }
    schedule();
  };
  const schedule = () => {
    if (stopped) return;
    timer = window.setTimeout(tick, nextDelay({ pushLive, hidden: document.hidden, failures, ageMs: Date.now() - started }));
  };
  const onVisible = () => { if (!document.hidden) { window.clearTimeout(timer); void tick(); } };
  document.addEventListener("visibilitychange", onVisible);
  window.addEventListener("online", onVisible);
  void tick();

  (async () => {
    try {
      const cfg = ((await (await fetch(`${API}/web/realtime/config`)).json()) as { data: Config }).data;
      if (stopped || cfg.driver !== "pusher") return;
      const { default: Pusher } = await import("pusher-js");
      const pusher = new Pusher(cfg.key, {
        cluster: cfg.cluster,
        ...(cfg.wsHost ? { wsHost: cfg.wsHost, wsPort: cfg.wsPort, wssPort: cfg.wsPort, enabledTransports: ["ws", "wss"] as ("ws" | "wss")[] } : {}),
        forceTLS: cfg.forceTLS ?? true,
        channelAuthorization: {
          endpoint: `${API}/web/realtime/auth`,
          transport: "ajax",
          customHandler: ({ socketId, channelName }, callback) => {
            fetch(`${API}/web/realtime/auth`, {
              method: "POST",
              headers: { "Content-Type": "application/json", Accept: "application/json", Authorization: `Bearer ${getToken() ?? ""}` },
              body: JSON.stringify({ socket_id: socketId, channel_name: channelName }),
            })
              .then((r) => (r.ok ? r.json() : Promise.reject(new Error("auth failed"))))
              .then((j: { auth: string }) => callback(null, { auth: j.auth }))
              .catch((e) => callback(e instanceof Error ? e : new Error("auth failed"), null));
          },
        },
      });
      pusher.connection.bind("connected", () => { pushLive = true; });
      for (const s of ["unavailable", "failed", "disconnected", "connecting"]) pusher.connection.bind(s, () => { pushLive = false; });
      const channel = pusher.subscribe(`private-order.${orderId}`);
      channel.bind("order.updated", () => onChange());
      disconnect = () => { channel.unbind_all(); pusher.unsubscribe(`private-order.${orderId}`); pusher.disconnect(); };
      if (stopped) disconnect();
    } catch {
      /* no push: polling already runs */
    }
  })();

  return () => {
    stopped = true;
    window.clearTimeout(timer);
    document.removeEventListener("visibilitychange", onVisible);
    window.removeEventListener("online", onVisible);
    disconnect?.();
  };
}
