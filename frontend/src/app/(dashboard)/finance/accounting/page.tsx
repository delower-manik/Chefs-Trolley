"use client";

import { useState } from "react";
import {
  useAccountingSummary,
  useExpenses,
  useExpenseCategories,
  createExpense,
  createExpenseCategory,
  deleteExpense,
} from "@/hooks/use-finance";
import { formatBDT, formatDateTime, startOfMonthInDhaka, todayInDhaka } from "@/lib/format";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";

// Dhaka calendar dates -- toISOString() would give the UTC date, which for a
// Dhaka browser is the previous day's date between midnight and 06:00 and
// pushes "start of this month" back to the previous month's last day.
const startOfMonth = startOfMonthInDhaka;
const today = todayInDhaka;

export default function AccountingPage() {
  const [from, setFrom] = useState(startOfMonth());
  const [to, setTo] = useState(today());
  const { summary, isLoading: summaryLoading } = useAccountingSummary(from, to);
  const { expenses, isLoading: expensesLoading, error, refresh } = useExpenses(from, to);
  const { categories, refresh: refreshCategories } = useExpenseCategories();
  const [formOpen, setFormOpen] = useState(false);

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div>
        <h2 className="text-2xl font-bold text-strong">Accounting</h2>
        <p className="text-sm text-text-secondary">
          Revenue vs expenses for the selected range — not a full bookkeeping system, just the honest numbers.
        </p>
      </div>

      <div className="card p-4 flex flex-wrap items-end gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">From</label>
          <input aria-label="From" type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="input-field" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">To</label>
          <input aria-label="To" type="date" value={to} onChange={(e) => setTo(e.target.value)} className="input-field" />
        </div>
        <Can permission="finance.manage">
          <button className="btn-primary ml-auto" onClick={() => setFormOpen(true)}>
            + Record expense
          </button>
        </Can>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <SummaryCard label="Revenue" value={summary?.totalRevenue} loading={summaryLoading} tone="success" />
        <SummaryCard label="Expenses" value={summary?.totalExpenses} loading={summaryLoading} tone="danger" />
        <SummaryCard label="Net profit" value={summary?.netProfit} loading={summaryLoading} tone="primary" />
      </div>

      {summary && summary.expensesByCategory.length > 0 && (
        <div className="card p-4">
          <h3 className="text-sm font-bold text-strong mb-2">Expenses by category</h3>
          <div className="space-y-1.5">
            {summary.expensesByCategory.map((c) => (
              <div key={c.category} className="flex justify-between text-sm">
                <span className="text-text-secondary">{c.category}</span>
                <span className="text-strong">{formatBDT(c.amount)}</span>
              </div>
            ))}
          </div>
        </div>
      )}

      {formOpen && (
        <ExpenseForm
          categories={categories}
          onClose={() => setFormOpen(false)}
          onSaved={() => {
            setFormOpen(false);
            refresh();
          }}
          onCategoryCreated={refreshCategories}
        />
      )}

      <div className="card overflow-hidden">
        {error ? (
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load expenses.</div>
        ) : expensesLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : expenses.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No expenses recorded in this range.</div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">Category</th>
                <th className="text-left p-3 font-medium">Description</th>
                <th className="text-left p-3 font-medium">Date</th>
                <th className="text-right p-3 font-medium">Amount</th>
                <th className="text-right p-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {expenses.map((exp) => (
                <tr key={exp.id} className="border-t border-border">
                  <td className="p-3 text-strong font-medium">
                    {exp.categoryName}
                    {exp.isSystemGenerated && <span className="badge bg-surface-hover text-text-secondary ml-2">Auto</span>}
                  </td>
                  <td className="p-3 text-text-secondary">{exp.description ?? "—"}</td>
                  <td className="p-3 text-text-secondary">{formatDateTime(exp.createdAt)}</td>
                  <td className="p-3 text-right text-strong">{formatBDT(exp.amount)}</td>
                  <td className="p-3 text-right">
                    {!exp.isSystemGenerated && (
                      <Can permission="finance.manage">
                        <button
                          className="text-danger text-xs font-medium"
                          onClick={async () => {
                            if (!window.confirm("Delete this expense?")) return;
                            try {
                              await deleteExpense(exp.id);
                              refresh();
                            } catch (err) {
                              window.alert(err instanceof ApiError ? err.message : "Couldn't delete this expense.");
                            }
                          }}
                        >
                          Delete
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

function SummaryCard({
  label,
  value,
  loading,
  tone,
}: {
  label: string;
  value?: number;
  loading: boolean;
  tone: "success" | "danger" | "primary";
}) {
  const toneClass = { success: "text-success", danger: "text-danger", primary: "text-primary-fg" }[tone];
  return (
    <div className="card p-4">
      <div className="text-sm text-text-secondary">{label}</div>
      {loading ? (
        <div className="h-7 w-24 bg-surface-hover rounded mt-2 animate-pulse" />
      ) : (
        <div className={`text-xl font-bold mt-1 ${toneClass}`}>{formatBDT(value ?? 0)}</div>
      )}
    </div>
  );
}

function ExpenseForm({
  categories,
  onClose,
  onSaved,
  onCategoryCreated,
}: {
  categories: { id: string; name: string }[];
  onClose: () => void;
  onSaved: () => void;
  onCategoryCreated: () => void;
}) {
  const [categoryId, setCategoryId] = useState(categories[0]?.id ?? "");
  const [newCategoryName, setNewCategoryName] = useState("");
  const [amount, setAmount] = useState("");
  const [description, setDescription] = useState("");
  const [expenseDate, setExpenseDate] = useState(today());
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      let finalCategoryId = categoryId;
      if (!finalCategoryId && newCategoryName.trim()) {
        const created = await createExpenseCategory(newCategoryName.trim());
        finalCategoryId = created.data.id;
        onCategoryCreated();
      }
      if (!finalCategoryId) {
        setError("Choose or create a category.");
        setSaving(false);
        return;
      }
      await createExpense({
        category_id: finalCategoryId,
        amount: Number(amount),
        description: description || undefined,
        expense_date: expenseDate,
      });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't record this expense.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Category</label>
          <select aria-label="Category" value={categoryId} onChange={(e) => setCategoryId(e.target.value)} className="input-field">
            <option value="">New category below...</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </div>
        {!categoryId && (
          <div>
            <label className="block text-xs font-medium text-text-secondary mb-1.5">New category name</label>
            <input aria-label="New category name" value={newCategoryName} onChange={(e) => setNewCategoryName(e.target.value)} className="input-field" placeholder="e.g. Rent" />
          </div>
        )}
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Amount (BDT)</label>
          <input aria-label="Amount (BDT)" required type="number" min={0} step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} className="input-field" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Date</label>
          <input aria-label="Date" required type="date" value={expenseDate} onChange={(e) => setExpenseDate(e.target.value)} className="input-field" />
        </div>
      </div>
      <div>
        <label className="block text-xs font-medium text-text-secondary mb-1.5">Description (optional)</label>
        <input aria-label="Description (optional)" value={description} onChange={(e) => setDescription(e.target.value)} className="input-field" />
      </div>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving..." : "Save expense"}
        </button>
      </div>
    </form>
  );
}
