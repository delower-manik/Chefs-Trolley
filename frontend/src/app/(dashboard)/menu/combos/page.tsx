"use client";

import { useState } from "react";
import { Can } from "@/components/ui/can";
import { ApiError } from "@/lib/api/client";
import { useFoodItems } from "@/hooks/use-menu";
import { createCombo, deleteCombo, updateCombo, useCombos } from "@/hooks/use-restaurant";
import { formatBDT } from "@/lib/format";
import { comboSavings, comboWindowLabel, isoToLocalInput, localInputToIso } from "@/lib/restaurant-utils";
import type { Combo } from "@/types/restaurant";

export default function CombosPage() {
  const { items, isLoading, error, refresh } = useCombos();
  const [editing, setEditing] = useState<Combo | "new" | null>(null);

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Combos &amp; Deals</h2>
          <p className="text-sm text-text-secondary">
            Fixed-price bundles, optionally limited to a date/time window (a deal). At checkout a combo is split into its dishes
            so the kitchen, stock and tax work per dish.
          </p>
        </div>
        <Can permission="menu.manage">
          <button className="btn-primary" onClick={() => setEditing("new")}>+ New combo</button>
        </Can>
      </div>

      {editing && (
        <ComboForm
          initial={editing === "new" ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={async () => {
            setEditing(null);
            await refresh();
          }}
        />
      )}

      {error ? (
        <div className="card p-10 text-center text-sm text-text-secondary">Couldn&apos;t load combos.</div>
      ) : isLoading ? (
        <div className="h-24 bg-surface-hover rounded-card animate-pulse" />
      ) : items.length === 0 ? (
        <div className="card p-10 text-center text-sm text-text-secondary">No combos yet.</div>
      ) : (
        <div className="grid md:grid-cols-2 gap-4">
          {items.map((c) => (
            <div key={c.id} className="card p-4 space-y-2">
              <div className="flex justify-between items-start">
                <div>
                  <div className="font-bold text-strong">{c.name}</div>
                  <div className="text-xs text-text-secondary">{comboWindowLabel(c)}</div>
                </div>
                <span className={`badge ${c.isOrderableNow ? "bg-success/15 text-success" : "bg-surface-hover text-text-secondary"}`}>
                  {c.isOrderableNow ? "On sale" : c.isActive ? "Scheduled / expired" : "Inactive"}
                </span>
              </div>
              <ul className="text-sm text-text-secondary">
                {c.items.map((i) => (
                  <li key={i.foodItemId}>{i.quantity}× {i.name}</li>
                ))}
              </ul>
              <div className="flex items-baseline gap-2">
                <span className="text-strong font-bold">{formatBDT(c.price)}</span>
                <span className="text-xs text-text-muted line-through">{formatBDT(c.regularPrice)}</span>
                {comboSavings(c) > 0 && <span className="text-xs text-success">save {formatBDT(comboSavings(c))}</span>}
              </div>
              <Can permission="menu.manage">
                <div className="flex gap-3 pt-1">
                  <button className="text-xs text-primary-fg" onClick={() => setEditing(c)}>Edit</button>
                  <button
                    className="text-xs text-danger"
                    onClick={async () => {
                      if (!window.confirm(`Delete ${c.name}? Past orders keep their lines.`)) return;
                      try {
                        await deleteCombo(c.id);
                        await refresh();
                      } catch (err) {
                        window.alert(err instanceof ApiError ? err.message : "Couldn't delete.");
                      }
                    }}
                  >
                    Delete
                  </button>
                </div>
              </Can>
            </div>
          ))}
        </div>
      )}
    </section>
  );
}

function ComboForm({ initial, onClose, onSaved }: { initial: Combo | null; onClose: () => void; onSaved: () => void }) {
  const { items: foodItems } = useFoodItems(undefined, undefined);
  const [name, setName] = useState(initial?.name ?? "");
  const [description, setDescription] = useState(initial?.description ?? "");
  const [price, setPrice] = useState(initial ? String(initial.price) : "");
  const [startsAt, setStartsAt] = useState(isoToLocalInput(initial?.startsAt ?? null));
  const [endsAt, setEndsAt] = useState(isoToLocalInput(initial?.endsAt ?? null));
  const [isActive, setIsActive] = useState(initial?.isActive ?? true);
  const [lines, setLines] = useState<{ food_item_id: string; quantity: number }[]>(
    initial?.items.map((i) => ({ food_item_id: i.foodItemId, quantity: i.quantity })) ?? [{ food_item_id: "", quantity: 1 }]
  );
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const regular = lines.reduce((sum, l) => sum + (foodItems.find((f) => f.id === l.food_item_id)?.basePrice ?? 0) * l.quantity, 0);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    const payload = {
      name,
      description: description || null,
      price: Number(price),
      starts_at: localInputToIso(startsAt),
      ends_at: localInputToIso(endsAt),
      is_active: isActive,
      items: lines.filter((l) => l.food_item_id),
    };
    try {
      if (initial) await updateCombo(initial.id, payload);
      else await createCombo(payload);
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't save this combo.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3">
      <h3 className="font-bold text-strong">{initial ? `Edit ${initial.name}` : "New combo"}</h3>
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Name</label>
          <input aria-label="Name" required value={name} onChange={(e) => setName(e.target.value)} className="input-field" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Combo price (BDT){regular > 0 && ` · regular ${formatBDT(regular)}`}</label>
          <input required type="number" min={0} step="0.01" value={price} onChange={(e) => setPrice(e.target.value)} className="input-field" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Available from (optional)</label>
          <input aria-label="Available from (optional)" type="datetime-local" value={startsAt} onChange={(e) => setStartsAt(e.target.value)} className="input-field" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Available until (optional)</label>
          <input aria-label="Available until (optional)" type="datetime-local" value={endsAt} onChange={(e) => setEndsAt(e.target.value)} className="input-field" />
        </div>
      </div>
      <input value={description} onChange={(e) => setDescription(e.target.value)} className="input-field" placeholder="Description (optional)" maxLength={500} />

      <div className="space-y-2">
        <div className="text-xs font-medium text-text-secondary">Dishes in this combo</div>
        {lines.map((l, i) => (
          <div key={i} className="flex gap-2">
            <select required value={l.food_item_id} onChange={(e) => setLines(lines.map((x, j) => (j === i ? { ...x, food_item_id: e.target.value } : x)))} className="input-field flex-1">
              <option value="">Choose a dish</option>
              {foodItems.map((f) => (
                <option key={f.id} value={f.id} disabled={lines.some((x, j) => j !== i && x.food_item_id === f.id)}>
                  {f.name} — {formatBDT(f.basePrice)}
                </option>
              ))}
            </select>
            <input type="number" min={1} max={20} value={l.quantity} onChange={(e) => setLines(lines.map((x, j) => (j === i ? { ...x, quantity: Number(e.target.value) } : x)))} className="input-field w-20" />
            {lines.length > 1 && (
              <button type="button" className="text-danger text-sm" onClick={() => setLines(lines.filter((_, j) => j !== i))}>Remove</button>
            )}
          </div>
        ))}
        <button type="button" className="text-xs text-primary-fg" onClick={() => setLines([...lines, { food_item_id: "", quantity: 1 }])}>+ Add dish</button>
      </div>

      <label className="flex items-center gap-2 text-sm text-text-secondary">
        <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} /> Active
      </label>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">Cancel</button>
        <button disabled={saving} className="btn-primary">{saving ? "Saving..." : "Save combo"}</button>
      </div>
    </form>
  );
}
