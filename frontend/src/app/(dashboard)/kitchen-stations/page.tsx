"use client";

import { useState } from "react";
import { Can } from "@/components/ui/can";
import { ApiError } from "@/lib/api/client";
import { createStation, deleteStation, updateStation, useStations } from "@/hooks/use-restaurant";

export default function KitchenStationsPage() {
  const { items, isLoading, error, refresh } = useStations();
  const [name, setName] = useState("");
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setFormError(null);
    try {
      await createStation({ name, sort_order: items.length });
      setName("");
      await refresh();
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : "Couldn't save this station.");
    } finally {
      setSaving(false);
    }
  }

  async function act(fn: () => Promise<unknown>) {
    try {
      await fn();
      await refresh();
    } catch (err) {
      window.alert(err instanceof ApiError ? err.message : "That didn't work. Try again.");
    }
  }

  return (
    <section className="max-w-3xl mx-auto space-y-5">
      <div>
        <h2 className="text-2xl font-bold text-strong">Kitchen Stations</h2>
        <p className="text-sm text-text-secondary">
          Route dishes to stations (Grill, Fry, Bar…) from the dish editor. Each station&apos;s screen shows only its own items and
          advances them independently; dishes without a station show on the unrouted view.
        </p>
      </div>

      <Can permission="kitchen.manage">
        <form onSubmit={handleCreate} className="card p-5 flex gap-3 items-end">
          <div className="flex-1">
            <label className="block text-xs font-medium text-text-secondary mb-1.5">New station</label>
            <input aria-label="New station" required value={name} onChange={(e) => setName(e.target.value)} className="input-field" placeholder="Grill" maxLength={60} />
          </div>
          <button disabled={saving} className="btn-primary">{saving ? "Saving..." : "Add"}</button>
        </form>
        {formError && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{formError}</div>}
      </Can>

      {error ? (
        <div className="card p-10 text-center text-sm text-text-secondary">Couldn&apos;t load stations.</div>
      ) : isLoading ? (
        <div className="h-24 bg-surface-hover rounded-card animate-pulse" />
      ) : items.length === 0 ? (
        <div className="card p-10 text-center text-sm text-text-secondary">No stations yet — every ticket shows on the single kitchen board.</div>
      ) : (
        <ul className="card divide-y divide-border">
          {items.map((s) => (
            <li key={s.id} className="p-4 flex items-center justify-between">
              <div>
                <div className="text-strong font-medium">{s.name}</div>
                <div className="text-xs text-text-secondary">{s.foodItemCount ?? 0} dishes routed here</div>
              </div>
              <div className="flex items-center gap-3">
                <span className={`badge ${s.isActive ? "bg-success/15 text-success" : "bg-surface-hover text-text-secondary"}`}>
                  {s.isActive ? "Active" : "Inactive"}
                </span>
                <Can permission="kitchen.manage">
                  <button className="text-xs text-text-secondary" onClick={() => act(() => updateStation(s.id, { is_active: !s.isActive }))}>
                    {s.isActive ? "Deactivate" : "Activate"}
                  </button>
                  <button
                    className="text-xs text-danger"
                    onClick={() => {
                      if (window.confirm(`Delete ${s.name}? Its dishes become unrouted.`)) void act(() => deleteStation(s.id));
                    }}
                  >
                    Delete
                  </button>
                </Can>
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
