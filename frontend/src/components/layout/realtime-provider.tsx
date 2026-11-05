"use client";

import { createContext, useContext, useEffect, useRef, useState } from "react";
import { useSWRConfig } from "swr";
import { useAuth } from "@/lib/auth/auth-context";
import { connectRealtime } from "@/lib/realtime/client";
import { shouldRevalidate, type RealtimeStatus } from "@/lib/realtime/events";

const StatusContext = createContext<RealtimeStatus>("off");

export const useRealtimeStatus = () => useContext(StatusContext);

/** Keeps every open list/board fresh: a pushed event makes the affected SWR caches reload. */
export function RealtimeProvider({ children }: { children: React.ReactNode }) {
  const { status: authStatus, user } = useAuth();
  const { mutate } = useSWRConfig();
  const [status, setStatus] = useState<RealtimeStatus>("off");
  const pending = useRef<ReturnType<typeof setTimeout> | null>(null);
  const events = useRef(new Set<string>());

  useEffect(() => {
    if (authStatus !== "authenticated") return;
    let disconnect: (() => void) | undefined;
    let cancelled = false;

    // Bursts (a kitchen bumping five tickets) collapse into one reload per event type.
    const flush = () => {
      pending.current = null;
      for (const event of events.current) void mutate((key) => shouldRevalidate(event, key));
      events.current.clear();
    };

    connectRealtime({
      onStatus: (s) => !cancelled && setStatus(s),
      onEvent: (event) => {
        events.current.add(event);
        if (!pending.current) pending.current = setTimeout(flush, 300);
      },
    }).then((d) => {
      if (cancelled) d();
      else disconnect = d;
    });

    return () => {
      cancelled = true;
      if (pending.current) clearTimeout(pending.current);
      disconnect?.();
      setStatus("off");
    };
    // Reconnect when the active branch changes: the channel is per branch.
  }, [authStatus, user?.activeBranch?.id, mutate]);

  return <StatusContext.Provider value={status}>{children}</StatusContext.Provider>;
}
