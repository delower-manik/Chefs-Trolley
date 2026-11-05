"use client";

import { useState } from "react";
import { useStations } from "@/hooks/use-restaurant";
import { useKitchenTickets, bumpTicket, reopenTicket } from "@/hooks/use-kitchen";
import { ApiError } from "@/lib/api/client";
import type { KitchenState, KitchenTicket } from "@/types/kitchen";

const COLUMNS: { state: KitchenState; label: string }[] = [
  { state: "new", label: "New" },
  { state: "accepted", label: "Accepted" },
  { state: "preparing", label: "Preparing" },
  { state: "ready", label: "Ready" },
];

const BUMP_LABEL: Record<KitchenState, string> = {
  new: "Accept",
  accepted: "Start preparing",
  preparing: "Mark ready",
  ready: "Serve",
  served: "Complete",
  completed: "Complete",
};

export default function KitchenDisplayPage() {
  const [stationId, setStationId] = useState<string>("");
  const { items: stations } = useStations();
  const { tickets, isLoading, error, refresh } = useKitchenTickets(stationId || undefined);

  if (error) {
    return (
      <div className="card p-8 text-center max-w-md mx-auto mt-12">
        <span className="material-symbols-outlined text-danger text-4xl">error</span>
        <h2 className="font-bold text-strong mt-3">Couldn&apos;t load the kitchen board</h2>
        <p className="text-sm text-text-secondary mt-1">Check that the backend is running and reachable.</p>
      </div>
    );
  }

  return (
    <section className="h-full flex flex-col">
      <div className="flex items-center justify-between mb-4">
        <div>
          <h2 className="text-2xl font-bold text-strong">Kitchen Display</h2>
          <p className="text-sm text-text-secondary">Live order queue · updates every few seconds</p>
        </div>
        <div className="flex items-center gap-2 text-xs text-text-secondary">
          <span className="w-2 h-2 rounded-full bg-success animate-pulse" />
          Auto-refreshing
        </div>
      </div>

      {stations.length > 0 && (
        <div className="flex gap-2 overflow-x-auto pb-3 shrink-0">
          {[{ id: "", name: "All stations" }, ...stations.filter((s) => s.isActive), { id: "none", name: "Unrouted" }].map((s) => (
            <button
              key={s.id || "all"}
              onClick={() => setStationId(s.id)}
              className={`px-3 py-1.5 rounded-full text-sm whitespace-nowrap ${
                stationId === s.id ? "bg-primary text-on-primary" : "bg-surface-hover text-text-secondary"
              }`}
            >
              {s.name}
            </button>
          ))}
        </div>
      )}

      <div className="grid grid-cols-1 md:grid-cols-4 gap-4 flex-1 min-h-0">
        {COLUMNS.map((col) => {
          const columnTickets = tickets.filter((t) => t.kitchenState === col.state);
          return (
            <div key={col.state} className="flex flex-col min-h-0">
              <div className="flex items-center justify-between px-1 pb-2">
                <h3 className="font-bold text-strong text-sm uppercase tracking-wide">{col.label}</h3>
                <span className="badge bg-surface-hover text-text-secondary">{columnTickets.length}</span>
              </div>
              <div className="flex-1 overflow-y-auto space-y-3 pr-1">
                {isLoading && columnTickets.length === 0 ? (
                  <div className="h-24 bg-surface-hover rounded-card animate-pulse" />
                ) : columnTickets.length === 0 ? (
                  <div className="text-xs text-text-muted text-center py-6">No tickets</div>
                ) : (
                  columnTickets.map((ticket) => (
                    <TicketCard key={ticket.id} ticket={ticket} stationId={stationId || undefined} onChanged={refresh} />
                  ))
                )}
              </div>
            </div>
          );
        })}
      </div>
    </section>
  );
}

function TicketCard({ ticket, stationId, onChanged }: { ticket: KitchenTicket; stationId?: string; onChanged: () => void }) {
  const elapsedMinutes = Math.max(0, Math.round((Date.now() - new Date(ticket.createdAt).getTime()) / 60000));
  const isUrgent = elapsedMinutes >= 15;

  async function handleBump() {
    try {
      await bumpTicket(ticket.id, stationId);
      onChanged();
    } catch (err) {
      window.alert(err instanceof ApiError ? err.message : "Couldn't update this ticket.");
    }
  }

  async function handleReopen() {
    try {
      await reopenTicket(ticket.id, stationId);
      onChanged();
    } catch (err) {
      window.alert(err instanceof ApiError ? err.message : "Couldn't reopen this ticket.");
    }
  }

  return (
    <div className={`card p-3 border-l-2 ${isUrgent ? "border-l-danger" : "border-l-primary"}`}>
      <div className="flex justify-between items-start mb-2">
        <span className="font-bold text-strong text-sm">{ticket.code}</span>
        <span className={`text-xs ${isUrgent ? "text-danger" : "text-text-secondary"}`}>{elapsedMinutes}m</span>
      </div>
      <div className="text-xs text-text-secondary mb-2 capitalize">
        {ticket.source.replace("_", " ")}
        {ticket.tableLabel ? ` · Table ${ticket.tableLabel}` : ""}
      </div>
      <ul className="space-y-1 mb-3">
        {ticket.items.map((item) => (
          <li key={item.id} className="text-sm text-strong">
            <span className="font-medium">{item.quantity}×</span> {item.name}
            {item.comboName && <span className="text-xs text-text-secondary"> (from {item.comboName})</span>}
            {item.modifiers.length > 0 && (
              <div className="text-xs text-text-secondary pl-4">{item.modifiers.map((m) => m.name).join(", ")}</div>
            )}
            {item.notes && <div className="text-xs text-warning pl-4">Note: {item.notes}</div>}
          </li>
        ))}
      </ul>
      <div className="flex gap-2">
        <button onClick={handleBump} className="btn-primary flex-1 py-1.5 text-xs">
          {BUMP_LABEL[ticket.kitchenState]}
        </button>
        {ticket.kitchenState !== "new" && (
          <button onClick={handleReopen} className="btn-secondary py-1.5 text-xs px-2" title="Undo last bump">
            Undo
          </button>
        )}
      </div>
    </div>
  );
}
