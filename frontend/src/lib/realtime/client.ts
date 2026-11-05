import { apiRequest } from "@/lib/api/client";
import type { RealtimeStatus } from "./events";

type Config =
  | { driver: "none" }
  | { driver: "pusher"; key: string; cluster: string; wsHost?: string; wsPort?: number; forceTLS?: boolean; branchId: string | null }
  | { driver: "ably"; branchId: string | null };

type Handlers = {
  onEvent: (event: string, data: unknown) => void;
  onStatus: (status: RealtimeStatus) => void;
};

/**
 * Connects to whichever provider the server says is configured. Both SDKs are loaded on demand, so a restaurant
 * running without real-time (the default) downloads neither. Returns a function that disconnects.
 * Failures never throw: the app keeps working on SWR's normal revalidation.
 */
export async function connectRealtime({ onEvent, onStatus }: Handlers): Promise<() => void> {
  let config: Config;
  try {
    config = (await apiRequest<{ data: Config }>("/realtime/config")).data;
  } catch {
    onStatus("off");
    return () => undefined;
  }
  if (config.driver === "none" || !("branchId" in config) || !config.branchId) {
    onStatus("off");
    return () => undefined;
  }

  onStatus("connecting");
  try {
    if (config.driver === "pusher") return await connectPusher(config, onEvent, onStatus);
    return await connectAbly(config, onEvent, onStatus);
  } catch {
    onStatus("offline");
    return () => undefined;
  }
}

async function connectPusher(
  config: Extract<Config, { driver: "pusher" }>,
  onEvent: Handlers["onEvent"],
  onStatus: Handlers["onStatus"]
): Promise<() => void> {
  const { default: Pusher } = await import("pusher-js");
  const pusher = new Pusher(config.key, {
    cluster: config.cluster,
    ...(config.wsHost ? { wsHost: config.wsHost, wsPort: config.wsPort, wssPort: config.wsPort, enabledTransports: ["ws", "wss"] as ("ws" | "wss")[] } : {}),
    forceTLS: config.forceTLS ?? true,
    channelAuthorization: {
      endpoint: "/realtime/auth",
      transport: "ajax",
      customHandler: ({ socketId, channelName }, callback) => {
        apiRequest<{ auth: string }>("/realtime/auth", { method: "POST", body: { socket_id: socketId, channel_name: channelName } })
          .then((res) => callback(null, { auth: res.auth }))
          .catch((err) => callback(err instanceof Error ? err : new Error("auth failed"), null));
      },
    },
  });

  pusher.connection.bind("connected", () => onStatus("live"));
  pusher.connection.bind("connecting", () => onStatus("connecting"));
  pusher.connection.bind("unavailable", () => onStatus("offline"));
  pusher.connection.bind("failed", () => onStatus("offline"));
  pusher.connection.bind("disconnected", () => onStatus("offline"));

  const channel = pusher.subscribe(`private-branch.${config.branchId}`);
  channel.bind_global((event: string, data: unknown) => {
    if (!event.startsWith("pusher:") && !event.startsWith("pusher_internal:")) onEvent(event, data);
  });

  return () => {
    channel.unbind_all();
    pusher.disconnect();
  };
}

type AblyGlobal = { Realtime: new (options: Record<string, unknown>) => import("ably").Realtime };

/** Ably's bundle can't go through Next's compiler, so it is a self-hosted script (see scripts/copy-ably.mjs). */
function loadAbly(): Promise<AblyGlobal> {
  const w = window as unknown as { Ably?: AblyGlobal };
  if (w.Ably) return Promise.resolve(w.Ably);
  return new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.src = "/vendor/ably.min.js";
    script.async = true;
    script.onload = () => (w.Ably ? resolve(w.Ably) : reject(new Error("Ably failed to initialise")));
    script.onerror = () => reject(new Error("Ably script could not be loaded"));
    document.head.appendChild(script);
  });
}

async function connectAbly(
  config: Extract<Config, { driver: "ably" }>,
  onEvent: Handlers["onEvent"],
  onStatus: Handlers["onStatus"]
): Promise<() => void> {
  const Ably = await loadAbly();
  const client = new Ably.Realtime({
    authCallback: (_params: unknown, callback: (error: string | null, token: unknown) => void) => {
      apiRequest<Record<string, unknown>>("/realtime/ably-token", { method: "POST" })
        .then((token) => callback(null, token))
        .catch((err) => callback(err instanceof Error ? err.message : "auth failed", null));
    },
  });

  client.connection.on("connected", () => onStatus("live"));
  client.connection.on("connecting", () => onStatus("connecting"));
  client.connection.on(["disconnected", "suspended", "failed"], () => onStatus("offline"));

  const channel = client.channels.get(`branch:${config.branchId}`);
  void channel.subscribe((message) => onEvent(message.name ?? "", message.data));

  return () => {
    channel.unsubscribe();
    client.close();
  };
}
