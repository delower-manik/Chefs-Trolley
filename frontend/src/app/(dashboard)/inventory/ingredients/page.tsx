"use client";

import { useState } from "react";
import { useAuth } from "@/lib/auth/auth-context";
import { useIngredients, createIngredient, updateIngredient, deleteIngredient } from "@/hooks/use-inventory";
import { useUnits } from "@/hooks/use-inventory";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";
import { formatBDT } from "@/lib/format";
import type { Ingredient } from "@/types/inventory";

export default function IngredientsPage() {
  const { user } = useAuth();
  const [search, setSearch] = useState("");
  const [lowStockOnly, setLowStockOnly] = useState(false);
  const { ingredients, isLoading, error, refresh } = useIngredients(search || undefined, lowStockOnly);
  const { units } = useUnits();
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Ingredient | null>(null);

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Ingredients</h2>
          <p className="text-sm text-text-secondary">Stock levels for {user?.activeBranch?.name ?? "your branch"}.</p>
        </div>
        <Can permission="inventory.manage">
          <button
            className="btn-primary"
            onClick={() => {
              setEditing(null);
              setFormOpen(true);
            }}
          >
            + New ingredient
          </button>
        </Can>
      </div>

      <div className="card p-4 flex flex-col sm:flex-row gap-3 items-start sm:items-center">
        <input
          className="input-field sm:max-w-xs"
          placeholder="Search ingredients..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <label className="flex items-center gap-2 text-sm text-text-secondary">
          <input type="checkbox" checked={lowStockOnly} onChange={(e) => setLowStockOnly(e.target.checked)} />
          Low stock only
        </label>
      </div>

      {formOpen && user?.activeBranch && (
        <IngredientForm
          branchId={user.activeBranch.id}
          units={units}
          initial={editing}
          onClose={() => setFormOpen(false)}
          onSaved={() => {
            setFormOpen(false);
            refresh();
          }}
        />
      )}

      <div className="card overflow-hidden">
        {error ? (
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load ingredients.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 5 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : ingredients.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No ingredients match these filters yet.</div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">Name</th>
                <th className="text-right p-3 font-medium">Stock</th>
                <th className="text-right p-3 font-medium">Reorder level</th>
                <th className="text-right p-3 font-medium">Cost / unit</th>
                <th className="text-right p-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {ingredients.map((ing) => (
                <tr key={ing.id} className="border-t border-border">
                  <td className="p-3 text-strong font-medium">
                    {ing.name}
                    {ing.isLowStock && <span className="badge bg-danger/15 text-danger ml-2">Low stock</span>}
                  </td>
                  <td className="p-3 text-right text-text-secondary">
                    {ing.currentStock} {ing.unitAbbreviation}
                  </td>
                  <td className="p-3 text-right text-text-secondary">
                    {ing.reorderLevel} {ing.unitAbbreviation}
                  </td>
                  <td className="p-3 text-right text-text-secondary">{formatBDT(ing.costPerUnit)}</td>
                  <td className="p-3 text-right">
                    <Can permission="inventory.manage">
                      <button
                        className="text-primary-fg text-xs font-medium mr-3"
                        onClick={() => {
                          setEditing(ing);
                          setFormOpen(true);
                        }}
                      >
                        Edit
                      </button>
                      <button
                        className="text-danger text-xs font-medium"
                        onClick={async () => {
                          if (!window.confirm(`Delete "${ing.name}"?`)) return;
                          try {
                            await deleteIngredient(ing.id);
                            refresh();
                          } catch (err) {
                            window.alert(err instanceof ApiError ? err.message : "Couldn't delete this ingredient.");
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

function IngredientForm({
  branchId,
  units,
  initial,
  onClose,
  onSaved,
}: {
  branchId: string;
  units: { id: string; name: string; abbreviation: string }[];
  initial: Ingredient | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [name, setName] = useState(initial?.name ?? "");
  const [unitId, setUnitId] = useState(initial?.unitId ?? units[0]?.id ?? "");
  const [reorderLevel, setReorderLevel] = useState(String(initial?.reorderLevel ?? 0));
  const [costPerUnit, setCostPerUnit] = useState(String(initial?.costPerUnit ?? 0));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      if (initial) {
        await updateIngredient(initial.id, {
          name,
          unit_id: unitId,
          reorder_level: Number(reorderLevel),
          cost_per_unit: Number(costPerUnit),
        });
      } else {
        await createIngredient({
          branch_id: branchId,
          name,
          unit_id: unitId,
          reorder_level: Number(reorderLevel),
          cost_per_unit: Number(costPerUnit),
        });
      }
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't save this ingredient.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Name</label>
          <input aria-label="Name" required value={name} onChange={(e) => setName(e.target.value)} className="input-field" placeholder="e.g. Basmati Rice" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Unit</label>
          <select aria-label="Unit" required value={unitId} onChange={(e) => setUnitId(e.target.value)} className="input-field">
            {units.map((u) => (
              <option key={u.id} value={u.id}>
                {u.name} ({u.abbreviation})
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Reorder level</label>
          <input aria-label="Reorder level"
            type="number"
            min={0}
            step="0.001"
            value={reorderLevel}
            onChange={(e) => setReorderLevel(e.target.value)}
            className="input-field"
          />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Cost per unit (BDT)</label>
          <input aria-label="Cost per unit (BDT)"
            type="number"
            min={0}
            step="0.01"
            value={costPerUnit}
            onChange={(e) => setCostPerUnit(e.target.value)}
            className="input-field"
          />
        </div>
      </div>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving..." : "Save ingredient"}
        </button>
      </div>
    </form>
  );
}
