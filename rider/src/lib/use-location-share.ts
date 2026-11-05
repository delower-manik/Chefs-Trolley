"use client";

import { useEffect, useRef, useState } from "react";
import { api, ApiError } from "./api";
import { shouldSend } from "./format";

export type GeoState = "idle" | "active" | "denied" | "unsupported";

/** While `active`, watches the GPS and sends fixes (throttled). The server decides when sharing is allowed; a 409 stops it here. */
export function useLocationShare(active: boolean, onStopped: () => void): GeoState {
  const [state, setState] = useState<GeoState>("idle");
  const last = useRef<{ lat: number; lng: number; at: number } | null>(null);
  const stopped = useRef(onStopped);
  stopped.current = onStopped;

  useEffect(() => {
    if (!active) { setState("idle"); last.current = null; return; }
    if (!("geolocation" in navigator)) { setState("unsupported"); return; }
    setState("active");
    let wake: { release: () => Promise<void> } | null = null;
    const nav = navigator as Navigator & { wakeLock?: { request: (t: "screen") => Promise<{ release: () => Promise<void> }> } };
    const holdScreen = () => nav.wakeLock?.request("screen").then((w) => { wake = w; }).catch(() => undefined); // keeps the screen (and GPS) awake; best effort
    void holdScreen();
    const onVisible = () => { if (document.visibilityState === "visible") void holdScreen(); };
    document.addEventListener("visibilitychange", onVisible);

    const id = navigator.geolocation.watchPosition(
      (p) => {
        const now = { lat: p.coords.latitude, lng: p.coords.longitude, at: Date.now() };
        if (!shouldSend(last.current, now)) return;
        last.current = now;
        api("/rider/location", { body: { lat: now.lat, lng: now.lng } }).catch((e) => { if (e instanceof ApiError && e.status === 409) stopped.current(); });
      },
      (err) => { if (err.code === err.PERMISSION_DENIED) { setState("denied"); stopped.current(); } },
      { enableHighAccuracy: true, maximumAge: 10000, timeout: 20000 },
    );
    return () => { navigator.geolocation.clearWatch(id); document.removeEventListener("visibilitychange", onVisible); void wake?.release(); };
  }, [active]);

  return state;
}
