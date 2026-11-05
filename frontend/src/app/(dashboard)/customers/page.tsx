"use client";

import { useState } from "react";
import { useCustomers, createCustomer, deleteCustomer, updateCustomer } from "@/hooks/use-customers";
import { useCustomerOrders, useLoyaltyLedger, adjustLoyalty } from "@/hooks/use-customers";
import { formatBDT, formatDateTime } from "@/lib/format";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";
import type { Customer } from "@/types/customer";

export default function CustomersPage() {
  const [search, setSearch] = useState("");
  const { customers, isLoading, error, refresh } = useCustomers(search || undefined);
  const [formOpen, setFormOpen] = useState(false);
  const [selected, setSelected] = useState<Customer | null>(null);

  return (
    <section className="max-w-6xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Customers</h2>
          <p className="text-sm text-text-secondary">Everyone who&apos;s ordered, their loyalty points, and their history.</p>
        </div>
        <Can permission="customers.manage">
          <button className="btn-primary" onClick={() => setFormOpen(true)}>
            + New customer
          </button>
        </Can>
      </div>

      <input
        className="input-field sm:max-w-xs"
        placeholder="Search by name or phone..."
        value={search}
        onChange={(e) => setSearch(e.target.value)}
      />

      {formOpen && (
        <NewCustomerForm
          onClose={() => setFormOpen(false)}
          onSaved={() => {
            setFormOpen(false);
            refresh();
          }}
        />
      )}

      <div className="grid lg:grid-cols-3 gap-5">
        <div className="lg:col-span-2 card overflow-hidden">
          {error ? (
            <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load customers.</div>
          ) : isLoading ? (
            <div className="p-4 space-y-3">
              {Array.from({ length: 5 }).map((_, i) => (
                <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
              ))}
            </div>
          ) : customers.length === 0 ? (
            <div className="p-10 text-center text-sm text-text-secondary">No customers yet.</div>
          ) : (
            <table className="w-full text-sm">
              <thead className="bg-surface-hover text-text-secondary">
                <tr>
                  <th className="text-left p-3 font-medium">Name</th>
                  <th className="text-left p-3 font-medium">Phone</th>
                  <th className="text-right p-3 font-medium">Points</th>
                  <th className="text-right p-3 font-medium">Actions</th>
                </tr>
              </thead>
              <tbody>
                {customers.map((c) => (
                  <tr
                    key={c.id}
                    className={`border-t border-border cursor-pointer hover:bg-surface-hover/50 ${selected?.id === c.id ? "bg-surface-hover" : ""}`}
                    onClick={() => setSelected(c)}
                  >
                    <td className="p-3 text-strong font-medium">{c.name}</td>
                    <td className="p-3 text-text-secondary">{c.phone}</td>
                    <td className="p-3 text-right text-primary-fg font-medium">{c.loyaltyPoints}</td>
                    <td className="p-3 text-right">
                      <Can permission="customers.manage">
                        <button
                          className="text-danger text-xs font-medium"
                          onClick={async (e) => {
                            e.stopPropagation();
                            if (!window.confirm(`Delete "${c.name}"?`)) return;
                            try {
                              await deleteCustomer(c.id);
                              if (selected?.id === c.id) setSelected(null);
                              refresh();
                            } catch (err) {
                              window.alert(err instanceof ApiError ? err.message : "Couldn't delete this customer.");
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

        <div>{selected ? <CustomerDetail customer={selected} /> : <EmptyDetailState />}</div>
      </div>
    </section>
  );
}

function EmptyDetailState() {
  return (
    <div className="card p-8 text-center text-sm text-text-secondary">
      Select a customer to see their order history and loyalty ledger.
    </div>
  );
}

function CustomerDetail({ customer }: { customer: Customer }) {
  const { orders, isLoading: ordersLoading } = useCustomerOrders(customer.id);
  const { entries, isLoading: ledgerLoading, refresh: refreshLedger } = useLoyaltyLedger(customer.id);
  const [adjusting, setAdjusting] = useState(false);
  const [optOut, setOptOut] = useState(customer.marketingOptOut);
  const [consentError, setConsentError] = useState<string | null>(null);

  return (
    <div className="space-y-4">
      <div className="card p-4">
        <h3 className="font-bold text-strong">{customer.name}</h3>
        <p className="text-xs text-text-secondary">{customer.phone}</p>
        <Can permission="customers.manage">
          <label className="mt-2 flex items-center gap-2 text-xs text-text-secondary">
            <input
              type="checkbox"
              checked={optOut}
              onChange={async (e) => {
                const next = e.target.checked;
                setOptOut(next);
                setConsentError(null);
                try {
                  await updateCustomer(customer.id, { name: customer.name, phone: customer.phone, marketing_opt_out: next });
                } catch {
                  setOptOut(!next);
                  setConsentError("Couldn't save that change.");
                }
              }}
            />
            Does not want marketing messages (SMS / email / push campaigns)
          </label>
          {consentError && <p className="text-xs text-danger">{consentError}</p>}
        </Can>
        <div className="mt-3 flex items-center justify-between">
          <span className="text-sm text-text-secondary">Loyalty points</span>
          <span className="text-lg font-bold text-primary-fg">{customer.loyaltyPoints}</span>
        </div>
        <Can permission="loyalty.manage">
          <button onClick={() => setAdjusting(true)} className="btn-secondary w-full mt-3 text-xs py-1.5">
            Adjust points
          </button>
        </Can>
      </div>

      {adjusting && (
        <LoyaltyAdjustForm
          customerId={customer.id}
          onClose={() => setAdjusting(false)}
          onSaved={() => {
            setAdjusting(false);
            refreshLedger();
          }}
        />
      )}

      <div className="card p-4">
        <h4 className="text-sm font-bold text-strong mb-2">Recent orders</h4>
        {ordersLoading ? (
          <div className="h-16 bg-surface-hover rounded animate-pulse" />
        ) : orders.length === 0 ? (
          <p className="text-xs text-text-secondary">No orders yet.</p>
        ) : (
          <div className="space-y-2">
            {orders.map((o) => (
              <div key={o.id} className="flex justify-between text-xs">
                <span className="text-text-secondary">
                  {o.code} · {formatDateTime(o.createdAt)}
                </span>
                <span className="text-strong font-medium">{formatBDT(o.grandTotal)}</span>
              </div>
            ))}
          </div>
        )}
      </div>

      <div className="card p-4">
        <h4 className="text-sm font-bold text-strong mb-2">Loyalty ledger</h4>
        {ledgerLoading ? (
          <div className="h-16 bg-surface-hover rounded animate-pulse" />
        ) : entries.length === 0 ? (
          <p className="text-xs text-text-secondary">No point activity yet.</p>
        ) : (
          <div className="space-y-2">
            {entries.map((e) => (
              <div key={e.id} className="flex justify-between text-xs">
                <span className="text-text-secondary">{e.reason ?? e.type}</span>
                <span className={e.pointsChange >= 0 ? "text-success" : "text-danger"}>
                  {e.pointsChange >= 0 ? "+" : ""}
                  {e.pointsChange}
                </span>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

function LoyaltyAdjustForm({ customerId, onClose, onSaved }: { customerId: string; onClose: () => void; onSaved: () => void }) {
  const [points, setPoints] = useState("");
  const [reason, setReason] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await adjustLoyalty(customerId, { points_change: Number(points), reason });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't adjust points.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-4 space-y-2">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-xs rounded-lg px-2 py-1.5">{error}</div>}
      <input
        required
        type="number"
        placeholder="+/- points"
        value={points}
        onChange={(e) => setPoints(e.target.value)}
        className="input-field text-sm"
      />
      <input
        required
        placeholder="Reason (required)"
        value={reason}
        onChange={(e) => setReason(e.target.value)}
        className="input-field text-sm"
      />
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary text-xs py-1.5 px-3">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary text-xs py-1.5 px-3">
          {saving ? "Saving..." : "Save"}
        </button>
      </div>
    </form>
  );
}

function NewCustomerForm({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await createCustomer({ name, phone, email: email || undefined });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't save this customer.");
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
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Phone</label>
          <input aria-label="Phone" required value={phone} onChange={(e) => setPhone(e.target.value)} className="input-field" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Email (optional)</label>
          <input aria-label="Email (optional)" type="email" value={email} onChange={(e) => setEmail(e.target.value)} className="input-field" />
        </div>
      </div>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving..." : "Save customer"}
        </button>
      </div>
    </form>
  );
}
