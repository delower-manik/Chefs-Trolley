"use client";

import { useState } from "react";
import { useSuppliers, createSupplier, deleteSupplier } from "@/hooks/use-inventory";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";

export default function SuppliersPage() {
  const { suppliers, isLoading, error, refresh } = useSuppliers();
  const [formOpen, setFormOpen] = useState(false);

  return (
    <section className="max-w-4xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Suppliers</h2>
          <p className="text-sm text-text-secondary">Vendors you buy ingredients from.</p>
        </div>
        <Can permission="inventory.manage">
          <button className="btn-primary" onClick={() => setFormOpen(true)}>
            + New supplier
          </button>
        </Can>
      </div>

      {formOpen && (
        <SupplierForm
          onClose={() => setFormOpen(false)}
          onSaved={() => {
            setFormOpen(false);
            refresh();
          }}
        />
      )}

      <div className="card overflow-hidden">
        {error ? (
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load suppliers.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : suppliers.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No suppliers added yet.</div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">Name</th>
                <th className="text-left p-3 font-medium">Contact</th>
                <th className="text-left p-3 font-medium">Phone</th>
                <th className="text-right p-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {suppliers.map((s) => (
                <tr key={s.id} className="border-t border-border">
                  <td className="p-3 text-strong font-medium">{s.name}</td>
                  <td className="p-3 text-text-secondary">{s.contactPerson ?? "—"}</td>
                  <td className="p-3 text-text-secondary">{s.phone ?? "—"}</td>
                  <td className="p-3 text-right">
                    <Can permission="inventory.manage">
                      <button
                        className="text-danger text-xs font-medium"
                        onClick={async () => {
                          if (!window.confirm(`Delete "${s.name}"?`)) return;
                          try {
                            await deleteSupplier(s.id);
                            refresh();
                          } catch (err) {
                            window.alert(err instanceof ApiError ? err.message : "Couldn't delete this supplier.");
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

function SupplierForm({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState("");
  const [contactPerson, setContactPerson] = useState("");
  const [phone, setPhone] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await createSupplier({ name, contact_person: contactPerson || undefined, phone: phone || undefined });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't save this supplier.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div className="grid sm:grid-cols-3 gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Name</label>
          <input aria-label="Name" required value={name} onChange={(e) => setName(e.target.value)} className="input-field" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Contact person</label>
          <input aria-label="Contact person" value={contactPerson} onChange={(e) => setContactPerson(e.target.value)} className="input-field" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Phone</label>
          <input aria-label="Phone" value={phone} onChange={(e) => setPhone(e.target.value)} className="input-field" />
        </div>
      </div>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving..." : "Save supplier"}
        </button>
      </div>
    </form>
  );
}
