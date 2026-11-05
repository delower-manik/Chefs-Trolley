"use client";

import { useState } from "react";
import Link from "next/link";
import { useCategories, createCategory, updateCategory, deleteCategory } from "@/hooks/use-menu";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";
import type { Category } from "@/types/menu";

export default function CategoriesPage() {
  const { categories, isLoading, error, refresh } = useCategories();
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Category | null>(null);

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Menu Categories</h2>
          <p className="text-sm text-text-secondary">Group your food items so they&apos;re easy to browse and manage.</p>
        </div>
        <Can permission="menu.manage">
          <button
            className="btn-primary"
            onClick={() => {
              setEditing(null);
              setFormOpen(true);
            }}
          >
            + New category
          </button>
        </Can>
      </div>

      {formOpen && (
        <CategoryForm
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
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load categories.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : categories.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">
            No categories yet. Create your first one to start building the menu.
          </div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">Name</th>
                <th className="text-left p-3 font-medium">Items</th>
                <th className="text-left p-3 font-medium">Status</th>
                <th className="text-right p-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {categories.map((cat) => (
                <tr key={cat.id} className="border-t border-border">
                  <td className="p-3 text-strong font-medium">{cat.name}</td>
                  <td className="p-3 text-text-secondary">
                    <Link href={`/menu/items?category_id=${cat.id}`} className="text-primary-fg">
                      {cat.foodItemCount ?? 0} items
                    </Link>
                  </td>
                  <td className="p-3">
                    <span className={`badge ${cat.isActive ? "bg-success/15 text-success" : "bg-surface-hover text-text-secondary"}`}>
                      {cat.isActive ? "Active" : "Inactive"}
                    </span>
                  </td>
                  <td className="p-3 text-right">
                    <Can permission="menu.manage">
                      <button
                        className="text-primary-fg text-xs font-medium mr-3"
                        onClick={() => {
                          setEditing(cat);
                          setFormOpen(true);
                        }}
                      >
                        Edit
                      </button>
                      <button
                        className="text-danger text-xs font-medium"
                        onClick={async () => {
                          if (!window.confirm(`Delete "${cat.name}"?`)) return;
                          try {
                            await deleteCategory(cat.id);
                            refresh();
                          } catch (err) {
                            window.alert(err instanceof ApiError ? err.message : "Couldn't delete this category.");
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

function CategoryForm({
  initial,
  onClose,
  onSaved,
}: {
  initial: Category | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [name, setName] = useState(initial?.name ?? "");
  const [description, setDescription] = useState(initial?.description ?? "");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      if (initial) {
        await updateCategory(initial.id, { name, description });
      } else {
        await createCategory({ name, description });
      }
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't save this category.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div>
        <label className="block text-xs font-medium text-text-secondary mb-1.5">Name</label>
        <input aria-label="Name" required value={name} onChange={(e) => setName(e.target.value)} className="input-field" placeholder="e.g. Biryani" />
      </div>
      <div>
        <label className="block text-xs font-medium text-text-secondary mb-1.5">Description (optional)</label>
        <textarea aria-label="Description (optional)"
          value={description ?? ""}
          onChange={(e) => setDescription(e.target.value)}
          className="input-field"
          rows={2}
        />
      </div>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving..." : "Save category"}
        </button>
      </div>
    </form>
  );
}
