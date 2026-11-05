"use client";

import { useState } from "react";
import { Can } from "@/components/ui/can";
import { ApiError } from "@/lib/api/client";
import { createTaxRate, deleteTaxRate, updateTaxRate, useTaxRates } from "@/hooks/use-restaurant";
import type { TaxRate } from "@/types/restaurant";

export default function TaxRatesPage() {
  const { items, isLoading, error, refresh } = useTaxRates();
  const [name, setName] = useState("");
  const [rate, setRate] = useState("");
  const [inclusive, setInclusive] = useState(false);
  const [isDefault, setIsDefault] = useState(false);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setFormError(null);
    try {
      await createTaxRate({ name, rate: Number(rate), is_inclusive: inclusive, is_default: isDefault });
      setName("");
      setRate("");
      setInclusive(false);
      setIsDefault(false);
      await refresh();
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : "Couldn't save this tax rate.");
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
    <section className="max-w-4xl mx-auto space-y-5">
      <div>
        <h2 className="text-2xl font-bold text-strong">Tax Rates</h2>
        <p className="text-sm text-text-secondary">
          Rates apply per branch. A dish uses its own rate if set, otherwise the branch default. Tax is computed on the
          pre-discount line total; <strong>inclusive</strong> rates are already part of the menu price.
        </p>
      </div>

      <Can permission="tax.manage">
        <form onSubmit={handleCreate} className="card p-5 space-y-3">
          {formError && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{formError}</div>}
          <div className="grid sm:grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium text-text-secondary mb-1.5">Name</label>
              <input aria-label="Name" required value={name} onChange={(e) => setName(e.target.value)} className="input-field" placeholder="VAT" />
            </div>
            <div>
              <label className="block text-xs font-medium text-text-secondary mb-1.5">Rate (%)</label>
              <input aria-label="Rate (%)" required type="number" min={0} max={100} step="0.01" value={rate} onChange={(e) => setRate(e.target.value)} className="input-field" />
            </div>
          </div>
          <div className="flex flex-wrap gap-5 text-sm text-text-secondary">
            <label className="flex items-center gap-2">
              <input type="checkbox" checked={inclusive} onChange={(e) => setInclusive(e.target.checked)} /> Price includes this tax
            </label>
            <label className="flex items-center gap-2">
              <input type="checkbox" checked={isDefault} onChange={(e) => setIsDefault(e.target.checked)} /> Branch default
            </label>
          </div>
          <div className="flex justify-end">
            <button disabled={saving} className="btn-primary">{saving ? "Saving..." : "Add tax rate"}</button>
          </div>
        </form>
      </Can>

      {error ? (
        <div className="card p-10 text-center text-sm text-text-secondary">Couldn&apos;t load tax rates.</div>
      ) : isLoading ? (
        <div className="h-24 bg-surface-hover rounded-card animate-pulse" />
      ) : items.length === 0 ? (
        <div className="card p-10 text-center text-sm text-text-secondary">No tax rates yet. Orders are currently untaxed unless a dish has its own flat rate.</div>
      ) : (
        <div className="card overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs text-text-secondary border-b border-border">
                <th className="p-3">Name</th>
                <th className="p-3 text-right">Rate</th>
                <th className="p-3">Type</th>
                <th className="p-3">Status</th>
                <th className="p-3" />
              </tr>
            </thead>
            <tbody>
              {items.map((r) => (
                <Row key={r.id} rate={r} act={act} />
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

function Row({ rate, act }: { rate: TaxRate; act: (fn: () => Promise<unknown>) => Promise<void> }) {
  return (
    <tr className="border-b border-border last:border-0">
      <td className="p-3 text-strong">
        {rate.name} {rate.isDefault && <span className="badge bg-primary/15 text-primary-fg ml-1">Default</span>}
      </td>
      <td className="p-3 text-right text-strong">{rate.rate}%</td>
      <td className="p-3 text-text-secondary">{rate.isInclusive ? "Inclusive" : "Added on top"}</td>
      <td className="p-3">
        <span className={`badge ${rate.isActive ? "bg-success/15 text-success" : "bg-surface-hover text-text-secondary"}`}>
          {rate.isActive ? "Active" : "Inactive"}
        </span>
      </td>
      <td className="p-3 text-right space-x-3 whitespace-nowrap">
        <Can permission="tax.manage">
          {!rate.isDefault && (
            <button className="text-xs text-primary-fg" onClick={() => act(() => updateTaxRate(rate.id, { is_default: true }))}>
              Make default
            </button>
          )}
          <button className="text-xs text-text-secondary" onClick={() => act(() => updateTaxRate(rate.id, { is_active: !rate.isActive }))}>
            {rate.isActive ? "Deactivate" : "Activate"}
          </button>
          <button
            className="text-xs text-danger"
            onClick={() => {
              if (window.confirm(`Delete ${rate.name}? Dishes using it fall back to the branch default.`)) void act(() => deleteTaxRate(rate.id));
            }}
          >
            Delete
          </button>
        </Can>
      </td>
    </tr>
  );
}
