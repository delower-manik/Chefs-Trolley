"use client";

import { useState } from "react";
import { useStockLedger, createStockAdjustment, useIngredients, useWarehouses } from "@/hooks/use-inventory";
import { formatDateTime } from "@/lib/format";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";
import type { StockLedgerType } from "@/types/inventory";

const TYPE_STYLE: Record<StockLedgerType, string> = {
  purchase: "bg-success/15 text-success",
  adjustment: "bg-primary/15 text-primary-fg",
  waste: "bg-danger/15 text-danger",
  sale_consumption: "bg-surface-hover text-text-secondary",
  correction: "bg-warning/15 text-warning",
  transfer_in: "bg-success/15 text-success",
  transfer_out: "bg-danger/15 text-danger",
};

export default function StockAdjustmentsPage() {
  const { entries, isLoading, error, refresh } = useStockLedger();
  const { ingredients } = useIngredients();
  const { warehouses } = useWarehouses();
  const [formOpen, setFormOpen] = useState(false);

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Stock Adjustments</h2>
          <p className="text-sm text-text-secondary">Every stock movement — purchases, sales, corrections, waste, and transfers.</p>
        </div>
        <Can permission="inventory.manage">
          <button className="btn-primary" onClick={() => setFormOpen(true)}>
            + Record adjustment
          </button>
        </Can>
      </div>

      {formOpen && (
        <AdjustmentForm
          ingredients={ingredients}
          warehouses={warehouses}
          onClose={() => setFormOpen(false)}
          onSaved={() => {
            setFormOpen(false);
            refresh();
          }}
        />
      )}

      <div className="card overflow-hidden">
        {error ? (
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load the stock ledger.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 5 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : entries.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No stock movements recorded yet.</div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">Ingredient</th>
                <th className="text-left p-3 font-medium">Warehouse</th>
                <th className="text-left p-3 font-medium">Type</th>
                <th className="text-right p-3 font-medium">Change</th>
                <th className="text-right p-3 font-medium">Balance after</th>
                <th className="text-left p-3 font-medium">Reason</th>
                <th className="text-left p-3 font-medium">When</th>
              </tr>
            </thead>
            <tbody>
              {entries.map((entry) => (
                <tr key={entry.id} className="border-t border-border">
                  <td className="p-3 text-strong font-medium">{entry.ingredientName}</td>
                  <td className="p-3 text-text-secondary">{entry.warehouseName}</td>
                  <td className="p-3">
                    <span className={`badge ${TYPE_STYLE[entry.type]}`}>{entry.type.replace("_", " ")}</span>
                  </td>
                  <td className={`p-3 text-right font-medium ${entry.quantityChange >= 0 ? "text-success" : "text-danger"}`}>
                    {entry.quantityChange >= 0 ? "+" : ""}
                    {entry.quantityChange}
                  </td>
                  <td className="p-3 text-right text-text-secondary">{entry.balanceAfter}</td>
                  <td className="p-3 text-text-secondary">{entry.reason ?? "—"}</td>
                  <td className="p-3 text-text-secondary">{formatDateTime(entry.createdAt)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </section>
  );
}

function AdjustmentForm({
  ingredients,
  warehouses,
  onClose,
  onSaved,
}: {
  ingredients: { id: string; name: string; unitAbbreviation: string | null }[];
  warehouses: { id: string; name: string; isDefault: boolean }[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [ingredientId, setIngredientId] = useState(ingredients[0]?.id ?? "");
  const [warehouseId, setWarehouseId] = useState(warehouses.find((w) => w.isDefault)?.id ?? warehouses[0]?.id ?? "");
  const [type, setType] = useState<"adjustment" | "waste">("waste");
  const [quantity, setQuantity] = useState("");
  const [reason, setReason] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const magnitude = Math.abs(Number(quantity));
      await createStockAdjustment({
        ingredient_id: ingredientId,
        warehouse_id: warehouseId,
        type,
        // Waste always reduces stock; a plain adjustment can go either way
        // depending on the sign the user enters.
        quantity_change: type === "waste" ? -magnitude : Number(quantity),
        reason: reason || undefined,
      });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't record this adjustment.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Ingredient</label>
          <select aria-label="Ingredient" required value={ingredientId} onChange={(e) => setIngredientId(e.target.value)} className="input-field">
            {ingredients.map((ing) => (
              <option key={ing.id} value={ing.id}>
                {ing.name}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Warehouse</label>
          <select aria-label="Warehouse" required value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} className="input-field">
            {warehouses.map((w) => (
              <option key={w.id} value={w.id}>
                {w.name} {w.isDefault ? "(default)" : ""}
              </option>
            ))}
          </select>
        </div>
      </div>
      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Type</label>
          <select aria-label="Type" value={type} onChange={(e) => setType(e.target.value as "adjustment" | "waste")} className="input-field">
            <option value="waste">Waste / spoilage</option>
            <option value="adjustment">Manual adjustment (+/-)</option>
          </select>
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">
            {type === "waste" ? "Quantity lost" : "Quantity change"}
          </label>
          <input
            required
            type="number"
            step="0.001"
            value={quantity}
            onChange={(e) => setQuantity(e.target.value)}
            className="input-field"
            placeholder={type === "adjustment" ? "e.g. -2 or 5" : "e.g. 2"}
          />
        </div>
      </div>
      <div>
        <label className="block text-xs font-medium text-text-secondary mb-1.5">
          Reason {type === "waste" && <span className="text-danger">*</span>}
        </label>
        <input
          required={type === "waste"}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          className="input-field"
          placeholder="e.g. Spoiled, damaged during prep"
        />
      </div>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving..." : "Save"}
        </button>
      </div>
    </form>
  );
}
