"use client";

import { useState } from "react";
import { usePurchases, createPurchase, receivePurchase, cancelPurchase } from "@/hooks/use-inventory";
import { useSuppliers, useIngredients, useWarehouses } from "@/hooks/use-inventory";
import { formatBDT, formatDateTime } from "@/lib/format";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";
import type { PurchaseStatus } from "@/types/inventory";

type DraftLine = { ingredientId: string; quantity: string; unitCost: string };

const STATUS_STYLE: Record<PurchaseStatus, string> = {
  draft: "bg-warning/15 text-warning",
  received: "bg-success/15 text-success",
  cancelled: "bg-danger/15 text-danger",
};

export default function PurchasesPage() {
  const { purchases, isLoading, error, refresh } = usePurchases();
  const { suppliers } = useSuppliers();
  const { ingredients } = useIngredients();
  const { warehouses } = useWarehouses();
  const [formOpen, setFormOpen] = useState(false);
  const [busyId, setBusyId] = useState<string | null>(null);

  async function handleReceive(id: string) {
    setBusyId(id);
    try {
      await receivePurchase(id);
      refresh();
    } catch (err) {
      window.alert(err instanceof ApiError ? err.message : "Couldn't receive this purchase.");
    } finally {
      setBusyId(null);
    }
  }

  async function handleCancel(id: string) {
    if (!window.confirm("Cancel this purchase order?")) return;
    setBusyId(id);
    try {
      await cancelPurchase(id);
      refresh();
    } catch (err) {
      window.alert(err instanceof ApiError ? err.message : "Couldn't cancel this purchase.");
    } finally {
      setBusyId(null);
    }
  }

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Purchases</h2>
          <p className="text-sm text-text-secondary">
            Placed as a draft PO first — stock only lands once you mark it received.
          </p>
        </div>
        <Can permission="inventory.manage">
          <button className="btn-primary" onClick={() => setFormOpen(true)}>
            + Record purchase
          </button>
        </Can>
      </div>

      {formOpen && (
        <NewPurchaseForm
          suppliers={suppliers}
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
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load purchases.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : purchases.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No purchases recorded yet.</div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">Supplier</th>
                <th className="text-left p-3 font-medium">Warehouse</th>
                <th className="text-left p-3 font-medium">Items</th>
                <th className="text-left p-3 font-medium">Status</th>
                <th className="text-right p-3 font-medium">Total</th>
                <th className="text-right p-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {purchases.map((p) => (
                <tr key={p.id} className="border-t border-border align-top">
                  <td className="p-3 text-strong font-medium">{p.supplierName}</td>
                  <td className="p-3 text-text-secondary">{p.warehouseName}</td>
                  <td className="p-3 text-text-secondary">
                    {p.items.map((i) => (
                      <div key={i.id}>
                        {i.quantity} × {i.ingredientName}
                      </div>
                    ))}
                  </td>
                  <td className="p-3">
                    <span className={`badge ${STATUS_STYLE[p.status]}`}>{p.status}</span>
                    {p.receivedAt && <div className="text-xs text-text-secondary mt-1">{formatDateTime(p.receivedAt)}</div>}
                  </td>
                  <td className="p-3 text-right font-medium text-strong">{formatBDT(p.totalCost)}</td>
                  <td className="p-3 text-right">
                    {p.status === "draft" && (
                      <Can permission="inventory.manage">
                        <button
                          disabled={busyId === p.id}
                          onClick={() => handleReceive(p.id)}
                          className="text-success text-xs font-medium mr-3"
                        >
                          Receive
                        </button>
                        <button
                          disabled={busyId === p.id}
                          onClick={() => handleCancel(p.id)}
                          className="text-danger text-xs font-medium"
                        >
                          Cancel
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

function NewPurchaseForm({
  suppliers,
  ingredients,
  warehouses,
  onClose,
  onSaved,
}: {
  suppliers: { id: string; name: string }[];
  ingredients: { id: string; name: string; unitAbbreviation: string | null }[];
  warehouses: { id: string; name: string; isDefault: boolean }[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [supplierId, setSupplierId] = useState(suppliers[0]?.id ?? "");
  const [warehouseId, setWarehouseId] = useState(warehouses.find((w) => w.isDefault)?.id ?? warehouses[0]?.id ?? "");
  const [lines, setLines] = useState<DraftLine[]>([{ ingredientId: ingredients[0]?.id ?? "", quantity: "", unitCost: "" }]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function updateLine(index: number, patch: Partial<DraftLine>) {
    setLines((prev) => prev.map((l, i) => (i === index ? { ...l, ...patch } : l)));
  }

  const total = lines.reduce((sum, l) => sum + (Number(l.quantity) || 0) * (Number(l.unitCost) || 0), 0);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!supplierId || !warehouseId) {
      setError("Add a supplier and a warehouse first.");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      await createPurchase({
        supplier_id: supplierId,
        warehouse_id: warehouseId,
        items: lines
          .filter((l) => l.ingredientId && Number(l.quantity) > 0)
          .map((l) => ({ ingredient_id: l.ingredientId, quantity: Number(l.quantity), unit_cost: Number(l.unitCost) })),
      });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't record this purchase.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Supplier</label>
          <select aria-label="Supplier" required value={supplierId} onChange={(e) => setSupplierId(e.target.value)} className="input-field">
            {suppliers.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Receiving warehouse</label>
          <select aria-label="Receiving warehouse" required value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} className="input-field">
            {warehouses.map((w) => (
              <option key={w.id} value={w.id}>
                {w.name} {w.isDefault ? "(default)" : ""}
              </option>
            ))}
          </select>
        </div>
      </div>

      <div className="space-y-2">
        {lines.map((line, i) => (
          <div key={i} className="grid grid-cols-[1fr_100px_100px_auto] gap-2 items-end">
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
            <div>
              <label className="block text-xs font-medium text-text-secondary mb-1">Unit cost</label>
              <input aria-label="Unit cost"
                type="number"
                min={0}
                step="0.01"
                value={line.unitCost}
                onChange={(e) => updateLine(i, { unitCost: e.target.value })}
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
          onClick={() => setLines((prev) => [...prev, { ingredientId: ingredients[0]?.id ?? "", quantity: "", unitCost: "" }])}
          className="text-primary-fg text-sm font-medium"
        >
          + Add line
        </button>
      </div>

      <div className="flex justify-between items-center border-t border-border pt-3">
        <span className="text-sm text-text-secondary">
          Total: <b className="text-strong">{formatBDT(total)}</b>
        </span>
        <div className="flex gap-2">
          <button type="button" onClick={onClose} className="btn-secondary">
            Cancel
          </button>
          <button type="submit" disabled={saving} className="btn-primary">
            {saving ? "Saving..." : "Save as draft PO"}
          </button>
        </div>
      </div>
    </form>
  );
}
