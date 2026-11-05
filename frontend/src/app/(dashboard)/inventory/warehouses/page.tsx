"use client";

import { useState } from "react";
import { useWarehouses, createWarehouse, updateWarehouse, deleteWarehouse } from "@/hooks/use-inventory";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";

export default function WarehousesPage() {
  const { warehouses, isLoading, error, refresh } = useWarehouses();
  const [formOpen, setFormOpen] = useState(false);

  return (
    <section className="max-w-3xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Warehouses</h2>
          <p className="text-sm text-text-secondary">
            Storage locations for this branch. Purchases land into one; transfers move stock between them.
          </p>
        </div>
        <Can permission="inventory.manage">
          <button className="btn-primary" onClick={() => setFormOpen(true)}>
            + New warehouse
          </button>
        </Can>
      </div>

      {formOpen && (
        <NewWarehouseForm
          onClose={() => setFormOpen(false)}
          onSaved={() => {
            setFormOpen(false);
            refresh();
          }}
        />
      )}

      <div className="card overflow-hidden">
        {error ? (
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load warehouses.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 2 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">Name</th>
                <th className="text-left p-3 font-medium">Default</th>
                <th className="text-right p-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {warehouses.map((w) => (
                <tr key={w.id} className="border-t border-border">
                  <td className="p-3 text-strong font-medium">{w.name}</td>
                  <td className="p-3">
                    {w.isDefault ? (
                      <span className="badge bg-primary/15 text-primary-fg">Default</span>
                    ) : (
                      <Can permission="inventory.manage">
                        <button
                          className="text-primary-fg text-xs font-medium"
                          onClick={async () => {
                            try {
                              await updateWarehouse(w.id, { name: w.name, is_default: true });
                              refresh();
                            } catch (err) {
                              window.alert(err instanceof ApiError ? err.message : "Couldn't update this warehouse.");
                            }
                          }}
                        >
                          Make default
                        </button>
                      </Can>
                    )}
                  </td>
                  <td className="p-3 text-right">
                    {!w.isDefault && (
                      <Can permission="inventory.manage">
                        <button
                          className="text-danger text-xs font-medium"
                          onClick={async () => {
                            if (!window.confirm(`Delete "${w.name}"?`)) return;
                            try {
                              await deleteWarehouse(w.id);
                              refresh();
                            } catch (err) {
                              window.alert(err instanceof ApiError ? err.message : "Couldn't delete this warehouse.");
                            }
                          }}
                        >
                          Delete
                        </button>
                      </Can>
                    )}
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

function NewWarehouseForm({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await createWarehouse({ name });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't create this warehouse.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div>
        <label className="block text-xs font-medium text-text-secondary mb-1.5">Name</label>
        <input aria-label="Name" required value={name} onChange={(e) => setName(e.target.value)} className="input-field" placeholder="e.g. Cold Storage" />
      </div>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving..." : "Save warehouse"}
        </button>
      </div>
    </form>
  );
}
