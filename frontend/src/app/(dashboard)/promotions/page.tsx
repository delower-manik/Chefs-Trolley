"use client";

import { useState } from "react";
import { usePromotions, createPromotion, updatePromotion, deletePromotion } from "@/hooks/use-growth";
import { formatBDT, formatDateTime } from "@/lib/format";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";
import type { PromotionType } from "@/types/growth";

export default function PromotionsPage() {
  const { promotions, isLoading, error, refresh } = usePromotions();
  const [formOpen, setFormOpen] = useState(false);

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Promotions & Coupons</h2>
          <p className="text-sm text-text-secondary">Codes customers can apply at checkout — validated and priced server-side.</p>
        </div>
        <Can permission="promotions.manage">
          <button className="btn-primary" onClick={() => setFormOpen(true)}>
            + New promotion
          </button>
        </Can>
      </div>

      {formOpen && (
        <NewPromotionForm
          onClose={() => setFormOpen(false)}
          onSaved={() => {
            setFormOpen(false);
            refresh();
          }}
        />
      )}

      <div className="card overflow-hidden">
        {error ? (
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load promotions.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : promotions.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No promotions yet.</div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">Code</th>
                <th className="text-left p-3 font-medium">Discount</th>
                <th className="text-left p-3 font-medium">Used</th>
                <th className="text-left p-3 font-medium">Expires</th>
                <th className="text-left p-3 font-medium">Status</th>
                <th className="text-right p-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {promotions.map((p) => (
                <tr key={p.id} className="border-t border-border">
                  <td className="p-3">
                    <div className="text-strong font-mono font-bold">{p.code}</div>
                    <div className="text-xs text-text-secondary">{p.name}</div>
                  </td>
                  <td className="p-3 text-text-secondary">
                    {p.type === "percentage" ? `${p.value}%` : formatBDT(p.value)}
                    {p.maxDiscountAmount && <div className="text-xs">up to {formatBDT(p.maxDiscountAmount)}</div>}
                  </td>
                  <td className="p-3 text-text-secondary">
                    {p.usedCount}
                    {p.usageLimit ? ` / ${p.usageLimit}` : ""}
                  </td>
                  <td className="p-3 text-text-secondary">{p.expiresAt ? formatDateTime(p.expiresAt) : "Never"}</td>
                  <td className="p-3">
                    <span className={`badge ${p.isActive ? "bg-success/15 text-success" : "bg-surface-hover text-text-secondary"}`}>
                      {p.isActive ? "Active" : "Paused"}
                    </span>
                  </td>
                  <td className="p-3 text-right">
                    <Can permission="promotions.manage">
                      <button
                        className="text-primary-fg text-xs font-medium mr-3"
                        onClick={async () => {
                          try {
                            await updatePromotion(p.id, { is_active: !p.isActive });
                            refresh();
                          } catch (err) {
                            window.alert(err instanceof ApiError ? err.message : "Couldn't update this promotion.");
                          }
                        }}
                      >
                        {p.isActive ? "Pause" : "Activate"}
                      </button>
                      <button
                        className="text-danger text-xs font-medium"
                        onClick={async () => {
                          if (!window.confirm(`Delete "${p.code}"?`)) return;
                          try {
                            await deletePromotion(p.id);
                            refresh();
                          } catch (err) {
                            window.alert(err instanceof ApiError ? err.message : "Couldn't delete this promotion.");
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

function NewPromotionForm({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [type, setType] = useState<PromotionType>("percentage");
  const [value, setValue] = useState("");
  const [minOrderAmount, setMinOrderAmount] = useState("");
  const [usageLimit, setUsageLimit] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await createPromotion({
        code,
        name,
        type,
        value: Number(value),
        min_order_amount: minOrderAmount ? Number(minOrderAmount) : undefined,
        usage_limit: usageLimit ? Number(usageLimit) : undefined,
      });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't save this promotion.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Code</label>
          <input aria-label="Code"
            required
            value={code}
            onChange={(e) => setCode(e.target.value.toUpperCase())}
            className="input-field font-mono"
            placeholder="SAVE10"
          />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Name</label>
          <input aria-label="Name" required value={name} onChange={(e) => setName(e.target.value)} className="input-field" placeholder="10% Off Weekend" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Type</label>
          <select aria-label="Type" value={type} onChange={(e) => setType(e.target.value as PromotionType)} className="input-field">
            <option value="percentage">Percentage</option>
            <option value="fixed">Fixed amount (BDT)</option>
          </select>
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Value</label>
          <input aria-label="Value"
            required
            type="number"
            min={0}
            step="0.01"
            value={value}
            onChange={(e) => setValue(e.target.value)}
            className="input-field"
            placeholder={type === "percentage" ? "10" : "50"}
          />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Min order amount (optional)</label>
          <input aria-label="Min order amount (optional)"
            type="number"
            min={0}
            value={minOrderAmount}
            onChange={(e) => setMinOrderAmount(e.target.value)}
            className="input-field"
          />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Usage limit (optional)</label>
          <input aria-label="Usage limit (optional)" type="number" min={1} value={usageLimit} onChange={(e) => setUsageLimit(e.target.value)} className="input-field" />
        </div>
      </div>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving..." : "Save promotion"}
        </button>
      </div>
    </form>
  );
}
