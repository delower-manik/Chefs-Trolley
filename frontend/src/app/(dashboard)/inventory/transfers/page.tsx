"use client";

import { useState } from "react";
import { useStockTransfers, createStockTransfer, useWarehouses, useIngredients } from "@/hooks/use-inventory";
import { formatDateTime } from "@/lib/format";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";

type DraftLine = { ingredientId: string; quantity: string };

export default function StockTransfersPage() {
  const { transfers, isLoading, error, refresh } = useStockTransfers();
  const { warehouses } = useWarehouses();
  const { ingredients } = useIngredients();
  const [formOpen, setFormOpen] = useState(false);

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Stock Transfers</h2>
          <p className="text-sm text-text-secondary">Move ingredients between warehouses in this branch.</p>
        </div>
        <Can permission="inventory.manage">
          <button className="btn-primary" onClick={() => setFormOpen(true)} disabled={warehouses.length < 2}>
            + New transfer
          </button>
        </Can>
      </div>

      {warehouses.length < 2 && (
        <div className="card p-4 text-sm text-text-secondary">
          You need at least two warehouses to record a transfer — add one on the Warehouses page first.
        </div>
      )}

      {formOpen && (
        <NewTransferForm
          warehouses={warehouses}
          ingredients={ingredients}
          onClose={() => setFormOpen(false)}
          onSaved={() => {
            setFormOpen(false);
            refresh();
          }}
        />
      )}

      <div className="card overflow-hidden">
        {error ? (
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load transfers.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : transfers.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No transfers recorded yet.</div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">From</th>
                <th className="text-left p-3 font-medium">To</th>
                <th className="text-left p-3 font-medium">Items</th>
                <th className="text-left p-3 font-medium">When</th>
              </tr>
            </thead>
            <tbody>
              {transfers.map((t) => (
                <tr key={t.id} className="border-t border-border align-top">
                  <td className="p-3 text-strong font-medium">{t.fromWarehouseName}</td>
                  <td className="p-3 text-strong font-medium">{t.toWarehouseName}</td>
                  <td className="p-3 text-text-secondary">
                    {t.items.map((i) => (
                      <div key={i.id}>
                        {i.quantity} × {i.ingredientName}
                      </div>
                    ))}
                  </td>
                  <td className="p-3 text-text-secondary">{formatDateTime(t.createdAt)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </section>
  );
}

function NewTransferForm({
  warehouses,
  ingredients,
  onClose,
  onSaved,
}: {
  warehouses: { id: string; name: string }[];
  ingredients: { id: string; name: string }[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [fromId, setFromId] = useState(warehouses[0]?.id ?? "");
  const [toId, setToId] = useState(warehouses[1]?.id ?? "");
  const [lines, setLines] = useState<DraftLine[]>([{ ingredientId: ingredients[0]?.id ?? "", quantity: "" }]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function updateLine(index: number, patch: Partial<DraftLine>) {
    setLines((prev) => prev.map((l, i) => (i === index ? { ...l, ...patch } : l)));
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (fromId === toId) {
      setError("Choose two different warehouses.");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      await createStockTransfer({
        from_warehouse_id: fromId,
        to_warehouse_id: toId,
        items: lines
          .filter((l) => l.ingredientId && Number(l.quantity) > 0)
          .map((l) => ({ ingredient_id: l.ingredientId, quantity: Number(l.quantity) })),
      });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't record this transfer.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">From</label>
          <select aria-label="From" required value={fromId} onChange={(e) => setFromId(e.target.value)} className="input-field">
            {warehouses.map((w) => (
              <option key={w.id} value={w.id}>
                {w.name}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">To</label>
          <select aria-label="To" required value={toId} onChange={(e) => setToId(e.target.value)} className="input-field">
            {warehouses.map((w) => (
              <option key={w.id} value={w.id}>
                {w.name}
              </option>
            ))}
          </select>
        </div>
      </div>

      <div className="space-y-2">
        {lines.map((line, i) => (
          <div key={i} className="grid grid-cols-[1fr_120px_auto] gap-2 items-end">
            <div>
              <label className="block text-xs font-medium text-text-secondary mb-1">Ingredient</label>
              <select aria-label="Ingredient"
                value={line.ingredientId}
                onChange={(e) => updateLine(i, { ingredientId: e.target.value })}
                className="input-field"
              >
                {ingredients.map((ing) => (
                  <option key={ing.id} value={ing.id}>
                    {ing.name}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-xs font-medium text-text-secondary mb-1">Qty</label>
              <input aria-label="Qty"
                type="number"
                min={0}
                step="0.001"
                value={line.quantity}
                onChange={(e) => updateLine(i, { quantity: e.target.value })}
                className="input-field"
              />
            </div>
            <button
              type="button"
              onClick={() => setLines((prev) => prev.filter((_, idx) => idx !== i))}
              className="text-danger text-xs pb-2.5"
              disabled={lines.length === 1}
            >
              Remove
            </button>
          </div>
        ))}
        <button
          type="button"
          onClick={() => setLines((prev) => [...prev, { ingredientId: ingredients[0]?.id ?? "", quantity: "" }])}
          className="text-primary-fg text-sm font-medium"
        >
          + Add line
        </button>
      </div>

      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Transferring..." : "Transfer stock"}
        </button>
      </div>
    </form>
  );
}
