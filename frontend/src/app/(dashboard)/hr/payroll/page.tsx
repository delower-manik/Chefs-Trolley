"use client";

import { useState } from "react";
import {
  usePayrollPeriods,
  usePayrollPeriod,
  createPayrollPeriod,
  generatePayroll,
  updatePayrollEntryBonus,
  finalizePayroll,
  payPayroll,
} from "@/hooks/use-finance";
import { formatBDT, formatDateTime } from "@/lib/format";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";
import type { PayrollStatus } from "@/types/finance";

const STATUS_STYLE: Record<PayrollStatus, string> = {
  draft: "bg-warning/15 text-warning",
  finalized: "bg-primary/15 text-primary-fg",
  paid: "bg-success/15 text-success",
};

export default function PayrollPage() {
  const { periods, isLoading, error, refresh } = usePayrollPeriods();
  const [formOpen, setFormOpen] = useState(false);
  const [selectedId, setSelectedId] = useState<string | null>(null);

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Payroll</h2>
          <p className="text-sm text-text-secondary">Generate from real attendance, review, finalize, then pay.</p>
        </div>
        <Can permission="payroll.manage">
          <button className="btn-primary" onClick={() => setFormOpen(true)}>
            + New payroll period
          </button>
        </Can>
      </div>

      {formOpen && (
        <NewPeriodForm
          onClose={() => setFormOpen(false)}
          onSaved={() => {
            setFormOpen(false);
            refresh();
          }}
        />
      )}

      <div className="grid lg:grid-cols-2 gap-5">
        <div className="card overflow-hidden">
          {error ? (
            <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load payroll periods.</div>
          ) : isLoading ? (
            <div className="p-4 space-y-3">
              {Array.from({ length: 3 }).map((_, i) => (
                <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
              ))}
            </div>
          ) : periods.length === 0 ? (
            <div className="p-10 text-center text-sm text-text-secondary">No payroll periods yet.</div>
          ) : (
            <table className="w-full text-sm">
              <thead className="bg-surface-hover text-text-secondary">
                <tr>
                  <th className="text-left p-3 font-medium">Period</th>
                  <th className="text-left p-3 font-medium">Status</th>
                  <th className="text-right p-3 font-medium">Total</th>
                </tr>
              </thead>
              <tbody>
                {periods.map((p) => (
                  <tr
                    key={p.id}
                    className={`border-t border-border cursor-pointer hover:bg-surface-hover/50 ${selectedId === p.id ? "bg-surface-hover" : ""}`}
                    onClick={() => setSelectedId(p.id)}
                  >
                    <td className="p-3 text-strong font-medium">
                      {p.periodStart} → {p.periodEnd}
                    </td>
                    <td className="p-3">
                      <span className={`badge ${STATUS_STYLE[p.status]}`}>{p.status}</span>
                    </td>
                    <td className="p-3 text-right text-text-secondary">{formatBDT(p.totalNetPay)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <div>
          {selectedId ? (
            <PeriodDetail periodId={selectedId} onChanged={refresh} />
          ) : (
            <div className="card p-8 text-center text-sm text-text-secondary">Select a period to review its entries.</div>
          )}
        </div>
      </div>
    </section>
  );
}

function PeriodDetail({ periodId, onChanged }: { periodId: string; onChanged: () => void }) {
  const { period, isLoading, refresh } = usePayrollPeriod(periodId);
  const [busy, setBusy] = useState(false);

  async function handleAction(action: () => Promise<unknown>) {
    setBusy(true);
    try {
      await action();
      refresh();
      onChanged();
    } catch (err) {
      window.alert(err instanceof ApiError ? err.message : "That action didn't go through.");
    } finally {
      setBusy(false);
    }
  }

  if (isLoading || !period) {
    return <div className="card p-6 h-64 animate-pulse" />;
  }

  return (
    <div className="card p-5 space-y-4">
      <div className="flex items-center justify-between">
        <span className={`badge ${STATUS_STYLE[period.status]}`}>{period.status}</span>
        {period.paidAt && <span className="text-xs text-text-secondary">Paid {formatDateTime(period.paidAt)}</span>}
      </div>

      <Can permission="payroll.manage">
        <div className="flex gap-2">
          {period.status === "draft" && (
            <>
              <button disabled={busy} onClick={() => handleAction(() => generatePayroll(period.id))} className="btn-secondary text-xs py-1.5">
                Generate entries
              </button>
              <button
                disabled={busy || period.entryCount === 0}
                onClick={() => handleAction(() => finalizePayroll(period.id))}
                className="btn-secondary text-xs py-1.5"
              >
                Finalize
              </button>
            </>
          )}
          {period.status === "finalized" && (
            <button disabled={busy} onClick={() => handleAction(() => payPayroll(period.id))} className="btn-primary text-xs py-1.5">
              Mark paid
            </button>
          )}
        </div>
      </Can>

      <div className="space-y-2">
        {(period.entries ?? []).length === 0 ? (
          <p className="text-xs text-text-secondary">No entries yet — generate them from the current active employees.</p>
        ) : (
          period.entries!.map((entry) => (
            <div key={entry.id} className="border-t border-border pt-2 text-sm">
              <div className="flex justify-between">
                <span className="text-strong font-medium">{entry.employeeName}</span>
                <span className="text-strong">{formatBDT(entry.netPay)}</span>
              </div>
              <div className="text-xs text-text-secondary flex justify-between mt-0.5">
                <span>
                  Base {formatBDT(entry.baseSalary)} · {entry.absentDays} absent day(s) · -{formatBDT(entry.deductions)}
                </span>
                {period.status === "draft" ? (
                  <Can permission="payroll.manage">
                    <button
                      className="text-primary-fg"
                      onClick={async () => {
                        const value = window.prompt("Bonus amount (BDT)", String(entry.bonuses));
                        if (value === null) return;
                        await handleAction(() => updatePayrollEntryBonus(entry.id, Number(value) || 0));
                      }}
                    >
                      Bonus: {formatBDT(entry.bonuses)}
                    </button>
                  </Can>
                ) : (
                  <span>Bonus {formatBDT(entry.bonuses)}</span>
                )}
              </div>
            </div>
          ))
        )}
      </div>
    </div>
  );
}

function NewPeriodForm({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const [start, setStart] = useState("");
  const [end, setEnd] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await createPayrollPeriod({ period_start: start, period_end: end });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't create this payroll period.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Period start</label>
          <input aria-label="Period start" required type="date" value={start} onChange={(e) => setStart(e.target.value)} className="input-field" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Period end</label>
          <input aria-label="Period end" required type="date" value={end} onChange={(e) => setEnd(e.target.value)} className="input-field" />
        </div>
      </div>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving..." : "Create period"}
        </button>
      </div>
    </form>
  );
}
