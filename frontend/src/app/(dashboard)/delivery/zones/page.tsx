"use client";

import { useState } from "react";
import { useDeliveryZones, createDeliveryZone, updateDeliveryZone, deleteDeliveryZone } from "@/hooks/use-delivery";
import { formatBDT } from "@/lib/format";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";

export default function DeliveryZonesPage() {
  const { zones, isLoading, error, refresh } = useDeliveryZones();
  const [formOpen, setFormOpen] = useState(false);

  return (
    <section className="max-w-3xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Delivery Zones</h2>
          <p className="text-sm text-text-secondary">Named areas with their own delivery fee — pick one at checkout to set the fee automatically.</p>
        </div>
        <Can permission="delivery.manage">
          <button className="btn-primary" onClick={() => setFormOpen(true)}>
            + New zone
          </button>
        </Can>
      </div>

      {formOpen && (
        <NewZoneForm
          onClose={() => setFormOpen(false)}
          onSaved={() => {
            setFormOpen(false);
            refresh();
          }}
        />
      )}

      <div className="card overflow-hidden">
        {error ? (
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load delivery zones.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : zones.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No delivery zones set up yet.</div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">Zone</th>
                <th className="text-right p-3 font-medium">Delivery fee</th>
                <th className="text-left p-3 font-medium">Status</th>
                <th className="text-right p-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {zones.map((z) => (
                <tr key={z.id} className="border-t border-border">
                  <td className="p-3 text-strong font-medium">{z.name}</td>
                  <td className="p-3 text-right text-text-secondary">{formatBDT(z.deliveryFee)}</td>
                  <td className="p-3">
                    <span className={`badge ${z.isActive ? "bg-success/15 text-success" : "bg-surface-hover text-text-secondary"}`}>
                      {z.isActive ? "Active" : "Inactive"}
                    </span>
                  </td>
                  <td className="p-3 text-right">
                    <Can permission="delivery.manage">
                      <button
                        className="text-primary-fg text-xs font-medium mr-3"
                        onClick={async () => {
                          try {
                            await updateDeliveryZone(z.id, { is_active: !z.isActive });
                            refresh();
                          } catch (err) {
                            window.alert(err instanceof ApiError ? err.message : "Couldn't update this zone.");
                          }
                        }}
                      >
                        {z.isActive ? "Deactivate" : "Activate"}
                      </button>
                      <button
                        className="text-danger text-xs font-medium"
                        onClick={async () => {
                          if (!window.confirm(`Delete "${z.name}"?`)) return;
                          try {
                            await deleteDeliveryZone(z.id);
                            refresh();
                          } catch (err) {
                            window.alert(err instanceof ApiError ? err.message : "Couldn't delete this zone.");
                          }
                        }}
                      >
                        Delete
                      </button>
                    </Can>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </section>
  );
}

function NewZoneForm({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState("");
  const [fee, setFee] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await createDeliveryZone({ name, delivery_fee: Number(fee) });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't save this zone.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Zone name</label>
          <input aria-label="Zone name" required value={name} onChange={(e) => setName(e.target.value)} className="input-field" placeholder="Inner City" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Delivery fee (BDT)</label>
          <input aria-label="Delivery fee (BDT)" required type="number" min={0} step="0.01" value={fee} onChange={(e) => setFee(e.target.value)} className="input-field" />
        </div>
      </div>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving..." : "Save zone"}
        </button>
      </div>
    </form>
  );
}
