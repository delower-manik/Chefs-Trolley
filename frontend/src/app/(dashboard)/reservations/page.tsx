"use client";

import { useState } from "react";
import { useAuth } from "@/lib/auth/auth-context";
import { useTables } from "@/hooks/use-tables";
import { useReservations, createReservation, cancelReservation } from "@/hooks/use-tables";
import { formatDateTime } from "@/lib/format";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";

const STATUS_STYLE: Record<string, string> = {
  pending: "bg-warning/15 text-warning",
  confirmed: "bg-primary/15 text-primary-fg",
  seated: "bg-success/15 text-success",
  completed: "bg-success/15 text-success",
  cancelled: "bg-danger/15 text-danger",
  no_show: "bg-danger/15 text-danger",
};

export default function ReservationsPage() {
  const { user } = useAuth();
  const { reservations, isLoading, error, refresh } = useReservations();
  const { tables } = useTables();
  const [formOpen, setFormOpen] = useState(false);

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Table Reservations</h2>
          <p className="text-sm text-text-secondary">Upcoming bookings across all tables.</p>
        </div>
        <Can permission="reservations.manage">
          <button className="btn-primary" onClick={() => setFormOpen(true)}>
            + New reservation
          </button>
        </Can>
      </div>

      {formOpen && user?.activeBranch && (
        <NewReservationForm
          branchId={user.activeBranch.id}
          tables={tables}
          onClose={() => setFormOpen(false)}
          onSaved={() => {
            setFormOpen(false);
            refresh();
          }}
        />
      )}

      <div className="card overflow-hidden">
        {error ? (
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load reservations.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : reservations.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No reservations booked yet.</div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">Customer</th>
                <th className="text-left p-3 font-medium">Table</th>
                <th className="text-left p-3 font-medium">Party</th>
                <th className="text-left p-3 font-medium">Time</th>
                <th className="text-left p-3 font-medium">Status</th>
                <th className="text-right p-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {reservations.map((r) => (
                <tr key={r.id} className="border-t border-border">
                  <td className="p-3">
                    <div className="text-strong font-medium">{r.customerName}</div>
                    <div className="text-xs text-text-secondary">{r.customerPhone}</div>
                  </td>
                  <td className="p-3 text-text-secondary">{r.tableLabel ?? "Unassigned"}</td>
                  <td className="p-3 text-text-secondary">{r.partySize}</td>
                  <td className="p-3 text-text-secondary">{formatDateTime(r.reservedFor)}</td>
                  <td className="p-3">
                    <span className={`badge ${STATUS_STYLE[r.status]}`}>{r.status.replace("_", " ")}</span>
                  </td>
                  <td className="p-3 text-right">
                    <Can permission="reservations.manage">
                      {(r.status === "pending" || r.status === "confirmed") && (
                        <button
                          className="text-danger text-xs font-medium"
                          onClick={async () => {
                            if (!window.confirm(`Cancel reservation for ${r.customerName}?`)) return;
                            try {
                              await cancelReservation(r.id);
                              refresh();
                            } catch (err) {
                              window.alert(err instanceof ApiError ? err.message : "Couldn't cancel this reservation.");
                            }
                          }}
                        >
                          Cancel
                        </button>
                      )}
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

function NewReservationForm({
  branchId,
  tables,
  onClose,
  onSaved,
}: {
  branchId: string;
  tables: { id: string; label: string }[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [customerName, setCustomerName] = useState("");
  const [customerPhone, setCustomerPhone] = useState("");
  const [partySize, setPartySize] = useState("2");
  const [tableId, setTableId] = useState("");
  const [reservedFor, setReservedFor] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await createReservation({
        branch_id: branchId,
        dining_table_id: tableId || undefined,
        customer_name: customerName,
        customer_phone: customerPhone,
        party_size: Number(partySize),
        reserved_for: new Date(reservedFor).toISOString(),
      });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't create this reservation.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Customer name</label>
          <input aria-label="Customer name" required value={customerName} onChange={(e) => setCustomerName(e.target.value)} className="input-field" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Phone</label>
          <input aria-label="Phone" required value={customerPhone} onChange={(e) => setCustomerPhone(e.target.value)} className="input-field" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Party size</label>
          <input aria-label="Party size" required type="number" min={1} value={partySize} onChange={(e) => setPartySize(e.target.value)} className="input-field" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Table (optional)</label>
          <select aria-label="Table (optional)" value={tableId} onChange={(e) => setTableId(e.target.value)} className="input-field">
            <option value="">Unassigned</option>
            {tables.map((t) => (
              <option key={t.id} value={t.id}>
                {t.label}
              </option>
            ))}
          </select>
        </div>
        <div className="sm:col-span-2">
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Reserved for</label>
          <input aria-label="Reserved for"
            required
            type="datetime-local"
            value={reservedFor}
            onChange={(e) => setReservedFor(e.target.value)}
            className="input-field"
          />
        </div>
      </div>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving..." : "Save reservation"}
        </button>
      </div>
    </form>
  );
}
