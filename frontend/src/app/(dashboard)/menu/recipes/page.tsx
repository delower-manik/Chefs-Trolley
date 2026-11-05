"use client";

import { useState } from "react";
import { useFoodItems } from "@/hooks/use-menu";
import { useIngredients, useRecipe, saveRecipe } from "@/hooks/use-inventory";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";

type DraftLine = { ingredientId: string; quantityRequired: string };

export default function RecipesPage() {
  const { items } = useFoodItems();
  const { ingredients } = useIngredients();
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const { recipe, isLoading, refresh } = useRecipe(selectedId);

  return (
    <section className="max-w-4xl mx-auto space-y-5">
      <div>
        <h2 className="text-2xl font-bold text-strong">Recipes</h2>
        <p className="text-sm text-text-secondary">
          What each dish consumes from inventory. Selling one of these automatically deducts the ingredients below.
        </p>
      </div>

      <div className="card p-4">
        <label className="block text-xs font-medium text-text-secondary mb-1.5">Food item</label>
        <select aria-label="Food item"
          value={selectedId ?? ""}
          onChange={(e) => setSelectedId(e.target.value || null)}
          className="input-field sm:max-w-sm"
        >
          <option value="">Choose a food item...</option>
          {items.map((item) => (
            <option key={item.id} value={item.id}>
              {item.name}
            </option>
          ))}
        </select>
      </div>

      {selectedId && (
        <div className="card p-5">
          {isLoading ? (
            <div className="h-32 bg-surface-hover rounded animate-pulse" />
          ) : (
            <RecipeEditor
              foodItemId={selectedId}
              ingredients={ingredients}
              initialLines={recipe?.ingredients ?? []}
              onSaved={refresh}
            />
          )}
        </div>
      )}
    </section>
  );
}

function RecipeEditor({
  foodItemId,
  ingredients,
  initialLines,
  onSaved,
}: {
  foodItemId: string;
  ingredients: { id: string; name: string; unitAbbreviation: string | null }[];
  initialLines: { ingredientId: string; quantityRequired: number }[];
  onSaved: () => void;
}) {
  const [lines, setLines] = useState<DraftLine[]>(
    initialLines.length > 0
      ? initialLines.map((l) => ({ ingredientId: l.ingredientId, quantityRequired: String(l.quantityRequired) }))
      : [{ ingredientId: ingredients[0]?.id ?? "", quantityRequired: "" }]
  );
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  function updateLine(index: number, patch: Partial<DraftLine>) {
    setLines((prev) => prev.map((l, i) => (i === index ? { ...l, ...patch } : l)));
    setSaved(false);
  }

  async function handleSave() {
    setSaving(true);
    setError(null);
    try {
      await saveRecipe(foodItemId, {
        ingredients: lines
          .filter((l) => l.ingredientId && Number(l.quantityRequired) > 0)
          .map((l) => ({ ingredient_id: l.ingredientId, quantity_required: Number(l.quantityRequired) })),
      });
      setSaved(true);
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't save this recipe.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-3">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      {saved && <div className="bg-success/10 border border-success/30 text-success text-sm rounded-lg px-3 py-2">Recipe saved.</div>}

      {ingredients.length === 0 ? (
        <p className="text-sm text-text-secondary">Add some ingredients first, then come back to build this recipe.</p>
      ) : (
        <>
          <div className="space-y-2">
            {lines.map((line, i) => {
              const ingredient = ingredients.find((ing) => ing.id === line.ingredientId);
              return (
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
                    <label className="block text-xs font-medium text-text-secondary mb-1">
                      Qty {ingredient?.unitAbbreviation ? `(${ingredient.unitAbbreviation})` : ""}
                    </label>
                    <input
                      type="number"
                      min={0}
                      step="0.001"
                      value={line.quantityRequired}
                      onChange={(e) => updateLine(i, { quantityRequired: e.target.value })}
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
              );
            })}
            <button
              type="button"
              onClick={() => setLines((prev) => [...prev, { ingredientId: ingredients[0]?.id ?? "", quantityRequired: "" }])}
              className="text-primary-fg text-sm font-medium"
            >
              + Add ingredient
            </button>
          </div>

          <Can permission="menu.manage">
            <div className="flex justify-end pt-2">
              <button onClick={handleSave} disabled={saving} className="btn-primary">
                {saving ? "Saving..." : "Save recipe"}
              </button>
            </div>
          </Can>
        </>
      )}
    </div>
  );
}
