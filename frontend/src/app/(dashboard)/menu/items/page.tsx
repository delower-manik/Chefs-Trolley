"use client";

import { useTaxRates, useStations } from "@/hooks/use-restaurant";
import { Suspense, useState } from "react";
import { useSearchParams } from "next/navigation";
import { useCategories, useFoodItems, createFoodItem, updateFoodItem, deleteFoodItem } from "@/hooks/use-menu";
import { formatBDT } from "@/lib/format";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";
import type { FoodItem, ModifierGroupInput } from "@/types/menu";

export default function FoodItemsPage() {
  return (
    <Suspense fallback={<div className="h-40 bg-surface-hover rounded-card animate-pulse max-w-6xl mx-auto" />}>
      <FoodItemsPageInner />
    </Suspense>
  );
}

function FoodItemsPageInner() {
  const searchParams = useSearchParams();
  const [categoryId, setCategoryId] = useState(searchParams.get("category_id") ?? "");
  const [search, setSearch] = useState("");
  const { categories } = useCategories();
  const { items, isLoading, error, refresh } = useFoodItems(categoryId || undefined, search || undefined);
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<FoodItem | null>(null);

  return (
    <section className="max-w-6xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Food Items</h2>
          <p className="text-sm text-text-secondary">Every dish, its price, and its modifiers.</p>
        </div>
        <Can permission="menu.manage">
          <button
            className="btn-primary"
            onClick={() => {
              setEditing(null);
              setFormOpen(true);
            }}
          >
            + New item
          </button>
        </Can>
      </div>

      <div className="card p-4 flex flex-col sm:flex-row gap-3">
        <input
          className="input-field sm:max-w-xs"
          placeholder="Search items..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <select aria-label="Filter by category" className="input-field sm:max-w-[220px]" value={categoryId} onChange={(e) => setCategoryId(e.target.value)}>
          <option value="">All categories</option>
          {categories.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </select>
      </div>

      {formOpen && (
        <FoodItemForm
          categories={categories}
          defaultCategoryId={categoryId}
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
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load food items.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 5 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : items.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">
            No food items match these filters yet.
          </div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">Item</th>
                <th className="text-left p-3 font-medium">Category</th>
                <th className="text-left p-3 font-medium">Modifiers</th>
                <th className="text-right p-3 font-medium">Price</th>
                <th className="text-left p-3 font-medium">Status</th>
                <th className="text-right p-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id} className="border-t border-border">
                  <td className="p-3 text-strong font-medium">{item.name}</td>
                  <td className="p-3 text-text-secondary">{item.categoryName}</td>
                  <td className="p-3 text-text-secondary">
                    {item.modifierGroups.length === 0
                      ? "—"
                      : `${item.modifierGroups.length} group${item.modifierGroups.length > 1 ? "s" : ""}`}
                  </td>
                  <td className="p-3 text-right text-strong">{formatBDT(item.basePrice)}</td>
                  <td className="p-3">
                    <span
                      className={`badge ${item.isAvailable ? "bg-success/15 text-success" : "bg-surface-hover text-text-secondary"}`}
                    >
                      {item.isAvailable ? "Available" : "Unavailable"}
                    </span>
                  </td>
                  <td className="p-3 text-right">
                    <Can permission="menu.manage">
                      <button
                        className="text-primary-fg text-xs font-medium mr-3"
                        onClick={() => {
                          setEditing(item);
                          setFormOpen(true);
                        }}
                      >
                        Edit
                      </button>
                      <button
                        className="text-danger text-xs font-medium"
                        onClick={async () => {
                          if (!window.confirm(`Delete "${item.name}"?`)) return;
                          try {
                            await deleteFoodItem(item.id);
                            refresh();
                          } catch (err) {
                            window.alert(err instanceof ApiError ? err.message : "Couldn't delete this item.");
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

function emptyGroup(): ModifierGroupInput {
  return { name: "", is_required: false, min_select: 0, max_select: 1, modifiers: [{ name: "", price: 0 }] };
}

function FoodItemForm({
  categories,
  defaultCategoryId,
  initial,
  onClose,
  onSaved,
}: {
  categories: { id: string; name: string }[];
  defaultCategoryId: string;
  initial: FoodItem | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [categoryId, setCategoryId] = useState(initial?.categoryId ?? defaultCategoryId ?? categories[0]?.id ?? "");
  const [name, setName] = useState(initial?.name ?? "");
  const [basePrice, setBasePrice] = useState(initial ? String(initial.basePrice) : "");
  const [isAvailable, setIsAvailable] = useState(initial?.isAvailable ?? true);
  const [taxRateId, setTaxRateId] = useState(initial?.taxRateId ?? "");
  const [stationId, setStationId] = useState(initial?.kitchenStationId ?? "");
  const { items: taxRates } = useTaxRates();
  const { items: stations } = useStations();
  const [groups, setGroups] = useState<ModifierGroupInput[]>(
    initial?.modifierGroups.map((g) => ({
      name: g.name,
      is_required: g.isRequired,
      min_select: g.minSelect,
      max_select: g.maxSelect,
      modifiers: g.modifiers.map((m) => ({ name: m.name, price: m.price })),
    })) ?? []
  );
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function updateGroup(index: number, patch: Partial<ModifierGroupInput>) {
    setGroups((gs) => gs.map((g, i) => (i === index ? { ...g, ...patch } : g)));
  }

  function updateModifier(groupIndex: number, modIndex: number, patch: Partial<{ name: string; price: number }>) {
    setGroups((gs) =>
      gs.map((g, i) =>
        i !== groupIndex ? g : { ...g, modifiers: g.modifiers.map((m, j) => (j === modIndex ? { ...m, ...patch } : m)) }
      )
    );
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!categoryId) {
      setError("Create a category first.");
      return;
    }
    const cleanedGroups = groups
      .filter((g) => g.name.trim() !== "")
      .map((g) => ({ ...g, modifiers: g.modifiers.filter((m) => m.name.trim() !== "") }));

    setSaving(true);
    setError(null);
    try {
      const payload = {
        category_id: categoryId,
        name,
        base_price: Number(basePrice),
        is_available: isAvailable,
        tax_rate_id: taxRateId || null,
        kitchen_station_id: stationId || null,
        modifier_groups: cleanedGroups,
      };
      if (initial) {
        await updateFoodItem(initial.id, payload);
      } else {
        await createFoodItem(payload);
      }
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't save this item.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-4">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}

      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Category</label>
          <select aria-label="Category" required value={categoryId} onChange={(e) => setCategoryId(e.target.value)} className="input-field">
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Base price (BDT)</label>
          <input aria-label="Base price (BDT)"
            required
            type="number"
            min={0}
            step="0.01"
            value={basePrice}
            onChange={(e) => setBasePrice(e.target.value)}
            className="input-field"
          />
        </div>
      </div>

      <div>
        <label className="block text-xs font-medium text-text-secondary mb-1.5">Name</label>
        <input aria-label="Name" required value={name} onChange={(e) => setName(e.target.value)} className="input-field" placeholder="e.g. Chicken Biryani" />
      </div>

      <div className="grid sm:grid-cols-2 gap-4">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Tax rate</label>
          <select aria-label="Tax rate" value={taxRateId} onChange={(e) => setTaxRateId(e.target.value)} className="input-field">
            <option value="">Branch default</option>
            {taxRates.filter((t) => t.isActive).map((t) => (
              <option key={t.id} value={t.id}>
                {t.name} ({t.rate}%{t.isInclusive ? ", inclusive" : ""})
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Kitchen station</label>
          <select aria-label="Kitchen station" value={stationId} onChange={(e) => setStationId(e.target.value)} className="input-field">
            <option value="">Unrouted</option>
            {stations.filter((t) => t.isActive).map((t) => (
              <option key={t.id} value={t.id}>{t.name}</option>
            ))}
          </select>
        </div>
      </div>

      <label className="flex items-center gap-2 text-sm text-text-secondary">
        <input type="checkbox" checked={isAvailable} onChange={(e) => setIsAvailable(e.target.checked)} className="accent-primary" />
        Available for sale
      </label>

      <div className="border-t border-border pt-4">
        <div className="flex items-center justify-between mb-3">
          <h4 className="text-sm font-bold text-strong">Modifier groups</h4>
          <button
            type="button"
            className="text-primary-fg text-xs font-medium"
            onClick={() => setGroups((gs) => [...gs, emptyGroup()])}
          >
            + Add group
          </button>
        </div>

        {groups.length === 0 && (
          <p className="text-xs text-text-muted">No modifier groups yet — e.g. &quot;Size&quot; or &quot;Add-ons&quot;.</p>
        )}

        <div className="space-y-4">
          {groups.map((group, gIndex) => (
            <div key={gIndex} className="bg-surface-raised border border-border rounded-lg p-3 space-y-2">
              <div className="flex gap-2 items-center">
                <input
                  value={group.name}
                  onChange={(e) => updateGroup(gIndex, { name: e.target.value })}
                  className="input-field flex-1"
                  placeholder="Group name, e.g. Size"
                />
                <label className="flex items-center gap-1.5 text-xs text-text-secondary whitespace-nowrap">
                  <input
                    type="checkbox"
                    checked={group.is_required}
                    onChange={(e) => updateGroup(gIndex, { is_required: e.target.checked })}
                    className="accent-primary"
                  />
                  Required
                </label>
                <button
                  type="button"
                  className="text-danger text-xs"
                  onClick={() => setGroups((gs) => gs.filter((_, i) => i !== gIndex))}
                >
                  Remove
                </button>
              </div>

              <div className="space-y-1.5 pl-2">
                {group.modifiers.map((mod, mIndex) => (
                  <div key={mIndex} className="flex gap-2 items-center">
                    <input
                      value={mod.name}
                      onChange={(e) => updateModifier(gIndex, mIndex, { name: e.target.value })}
                      className="input-field flex-1 py-1.5 text-xs"
                      placeholder="Modifier name, e.g. Large"
                    />
                    <input
                      type="number"
                      min={0}
                      step="0.01"
                      value={mod.price}
                      onChange={(e) => updateModifier(gIndex, mIndex, { price: Number(e.target.value) })}
                      className="input-field w-28 py-1.5 text-xs"
                      placeholder="+ price"
                    />
                    <button
                      type="button"
                      className="text-danger text-xs"
                      onClick={() =>
                        updateGroup(gIndex, { modifiers: group.modifiers.filter((_, i) => i !== mIndex) })
                      }
                    >
                      ✕
                    </button>
                  </div>
                ))}
                <button
                  type="button"
                  className="text-primary-fg text-xs font-medium"
                  onClick={() => updateGroup(gIndex, { modifiers: [...group.modifiers, { name: "", price: 0 }] })}
                >
                  + Add modifier
                </button>
              </div>
            </div>
          ))}
        </div>
      </div>

      <div className="flex gap-2 justify-end pt-2">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving..." : "Save item"}
        </button>
      </div>
    </form>
  );
}
