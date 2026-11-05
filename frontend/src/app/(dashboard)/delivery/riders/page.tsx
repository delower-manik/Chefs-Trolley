"use client";

import { useEffect, useState } from "react";
import { apiRequest } from "@/lib/api/client";
import { useRiders, createRider, updateRider, deleteRider } from "@/hooks/use-delivery";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";
import type { RiderStatus, VehicleType } from "@/types/delivery";

const STATUS_STYLE: Record<RiderStatus, string> = {
  available: "bg-success/15 text-success",
  busy: "bg-warning/15 text-warning",
  offline: "bg-surface-hover text-text-secondary",
};

export default function RidersPage() {
  const { riders, isLoading, error, refresh } = useRiders();
  const [formOpen, setFormOpen] = useState(false);

  return (
    <section className="max-w-4xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Riders</h2>
          <p className="text-sm text-text-secondary">Your delivery fleet and who&apos;s free right now.</p>
        </div>
        <Can permission="delivery.manage">
          <button className="btn-primary" onClick={() => setFormOpen(true)}>
            + New rider
          </button>
        </Can>
      </div>

      {formOpen && (
        <NewRiderForm
          onClose={() => setFormOpen(false)}
          onSaved={() => {
            setFormOpen(false);
            refresh();
          }}
        />
      )}

      <div className="card overflow-hidden">
        {error ? (
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load riders.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : riders.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No riders added yet.</div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">Name</th>
                <th className="text-left p-3 font-medium">Phone</th>
                <th className="text-left p-3 font-medium">Vehicle</th>
                <th className="text-left p-3 font-medium">App login</th>
                <th className="text-left p-3 font-medium">Status</th>
                <th className="text-right p-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {riders.map((r) => (
                <tr key={r.id} className="border-t border-border">
                  <td className="p-3 text-strong font-medium">{r.name}</td>
                  <td className="p-3 text-text-secondary">{r.phone}</td>
                  <td className="p-3 text-text-secondary capitalize">{r.vehicleType.replace("_", " ")}</td>
                  <td className="p-3 text-text-secondary">{r.userId ? "Linked" : "Not linked"}</td>
                  <td className="p-3">
                    <Can
                      permission="delivery.manage"
                      fallback={<span className={`badge ${STATUS_STYLE[r.status]}`}>{r.status}</span>}
                    >
                      <select
                        className={`badge border-0 text-xs ${STATUS_STYLE[r.status]}`}
                        value={r.status}
                        onChange={async (e) => {
                          try {
                            await updateRider(r.id, { status: e.target.value as RiderStatus });
                            refresh();
                          } catch (err) {
                            window.alert(err instanceof ApiError ? err.message : "Couldn't update this rider.");
                          }
                        }}
                      >
                        <option value="available">available</option>
                        <option value="busy">busy</option>
                        <option value="offline">offline</option>
                      </select>
                    </Can>
                  </td>
                  <td className="p-3 text-right">
                    <Can permission="delivery.manage">
                      <button
                        className="text-danger text-xs font-medium"
                        onClick={async () => {
                          if (!window.confirm(`Remove "${r.name}"?`)) return;
                          try {
                            await deleteRider(r.id);
                            refresh();
                          } catch (err) {
                            window.alert(err instanceof ApiError ? err.message : "Couldn't remove this rider.");
                          }
                        }}
                      >
                        Remove
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

function NewRiderForm({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [vehicleType, setVehicleType] = useState<VehicleType>("motorbike");
  const [userId, setUserId] = useState("");
  const [logins, setLogins] = useState<{ id: string; name: string; email: string }[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Staff accounts with the Rider role, so the rider can sign in to the Rider app. Needs users.manage; without it the list stays empty and the link can be set later by an admin.
  useEffect(() => {
    apiRequest<{ data: { id: string; name: string; email: string; roles: { name: string }[] }[] }>("/users")
      .then((r) => setLogins(r.data.filter((u) => u.roles.some((x) => x.name === "Rider"))))
      .catch(() => setLogins([]));
  }, []);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await createRider({ name, phone, vehicle_type: vehicleType, user_id: userId || null });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't save this rider.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div className="grid sm:grid-cols-4 gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Name</label>
          <input aria-label="Name" required value={name} onChange={(e) => setName(e.target.value)} className="input-field" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Phone</label>
          <input aria-label="Phone" required value={phone} onChange={(e) => setPhone(e.target.value)} className="input-field" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Vehicle</label>
          <select aria-label="Vehicle" value={vehicleType} onChange={(e) => setVehicleType(e.target.value as VehicleType)} className="input-field">
            <option value="motorbike">Motorbike</option>
            <option value="bicycle">Bicycle</option>
            <option value="car">Car</option>
            <option value="on_foot">On foot</option>
          </select>
        </div>
        {logins.length > 0 && (
          <div>
            <label className="block text-xs font-medium text-text-secondary mb-1.5">Rider app login</label>
            <select aria-label="Rider app login" value={userId} onChange={(e) => setUserId(e.target.value)} className="input-field">
              <option value="">None</option>
              {logins.map((u) => (
                <option key={u.id} value={u.id}>{u.name} ({u.email})</option>
              ))}
            </select>
          </div>
        )}
      </div>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving..." : "Save rider"}
        </button>
      </div>
    </form>
  );
}
