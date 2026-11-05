"use client";

import { useState } from "react";
import { useAuth } from "@/lib/auth/auth-context";
import { useTables, createTable, updateTable, deleteTable } from "@/hooks/use-tables";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";
import type { TableStatus } from "@/types/table";

const STATUS_STYLE: Record<TableStatus, string> = {
  available: "bg-success/15 text-success",
  occupied: "bg-danger/15 text-danger",
  reserved: "bg-warning/15 text-warning",
  out_of_service: "bg-surface-hover text-text-secondary",
};

export default function TablesPage() {
  const { user } = useAuth();
  const { tables, isLoading, error, refresh } = useTables();
  const [formOpen, setFormOpen] = useState(false);

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Tables</h2>
          <p className="text-sm text-text-secondary">Dining tables for {user?.activeBranch?.name ?? "your branch"}.</p>
        </div>
        <Can permission="tables.manage">
          <button className="btn-primary" onClick={() => setFormOpen(true)}>
            + New table
          </button>
        </Can>
      </div>

      {formOpen && user?.activeBranch && (
        <NewTableForm
          branchId={user.activeBranch.id}
          onClose={() => setFormOpen(false)}
          onSaved={() => {
            setFormOpen(false);
            refresh();
          }}
        />
      )}

      {error ? (
        <div className="card p-10 text-center text-sm text-text-secondary">Couldn&apos;t load tables.</div>
      ) : isLoading ? (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
          {Array.from({ length: 8 }).map((_, i) => (
            <div key={i} className="h-24 bg-surface-hover rounded-card animate-pulse" />
          ))}
        </div>
      ) : tables.length === 0 ? (
        <div className="card p-10 text-center text-sm text-text-secondary">No tables set up yet for this branch.</div>
      ) : (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
          {tables.map((table) => (
            <div key={table.id} className="card p-4">
              <div className="flex justify-between items-start">
                <span className="font-bold text-strong text-lg">{table.label}</span>
                <span className={`badge ${STATUS_STYLE[table.status]}`}>{table.status.replace("_", " ")}</span>
              </div>
              <div className="text-xs text-text-secondary mt-1">Seats {table.capacity}</div>
              <Can permission="tables.manage">
                <div className="flex gap-2 mt-3">
                  <select
                    className="input-field text-xs py-1"
                    value={table.status}
                    onChange={async (e) => {
                      try {
                        await updateTable(table.id, { status: e.target.value as TableStatus });
                        refresh();
                      } catch (err) {
                        window.alert(err instanceof ApiError ? err.message : "Couldn't update status.");
                      }
                    }}
                  >
                    <option value="available">Available</option>
                    <option value="occupied">Occupied</option>
                    <option value="reserved">Reserved</option>
                    <option value="out_of_service">Out of service</option>
                  </select>
                  <button
                    className="text-danger text-xs"
                    onClick={async () => {
                      if (!window.confirm(`Delete table ${table.label}?`)) return;
                      await deleteTable(table.id);
                      refresh();
                    }}
                  >
                    Delete
                  </button>
                </div>
              </Can>
            </div>
          ))}
        </div>
      )}
    </section>
  );
}

function NewTableForm({ branchId, onClose, onSaved }: { branchId: string; onClose: () => void; onSaved: () => void }) {
  const [label, setLabel] = useState("");
  const [capacity, setCapacity] = useState("4");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await createTable({ branch_id: branchId, label, capacity: Number(capacity) });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't create this table.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Table label</label>
          <input aria-label="Table label" required value={label} onChange={(e) => setLabel(e.target.value)} className="input-field" placeholder="T-12" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Capacity</label>
          <input aria-label="Capacity" required type="number" min={1} value={capacity} onChange={(e) => setCapacity(e.target.value)} className="input-field" />
        </div>
      </div>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving..." : "Save table"}
        </button>
      </div>
    </form>
  );
}
