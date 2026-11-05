"use client";

import { useState, type ReactNode } from "react";
import {
  downloadSalesCsv,
  useBranchesReport,
  useCategoriesReport,
  useCustomersReport,
  useDeliveryReport,
  useProductsReport,
  useSalesReport,
} from "@/hooks/use-reports";
import { ApiError } from "@/lib/api/client";
import { formatBDT, formatDate, startOfMonthInDhaka, todayInDhaka } from "@/lib/format";

const MAX_RANGE_DAYS = 366;

type TabId = "sales" | "products" | "categories" | "delivery" | "customers" | "branches";

const TABS: { id: TabId; label: string }[] = [
  { id: "sales", label: "Sales" },
  { id: "products", label: "Products" },
  { id: "categories", label: "Categories" },
  { id: "delivery", label: "Delivery" },
  { id: "customers", label: "Customers" },
  { id: "branches", label: "Branch comparison" },
];

/** Returns a human-readable problem with the range, or null when it is fine to query. */
function validateRange(from: string, to: string): string | null {
  if (!from || !to) return "Choose both a start and an end date.";
  if (from > to) return "The start date must not be after the end date.";
  const days = (Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000;
  if (days > MAX_RANGE_DAYS) return `Pick a range of at most ${MAX_RANGE_DAYS} days.`;
  return null;
}

function humanize(value: string): string {
  const text = value.replace(/_/g, " ");
  return text.charAt(0).toUpperCase() + text.slice(1);
}

export default function ReportsPage() {
  const [from, setFrom] = useState(startOfMonthInDhaka());
  const [to, setTo] = useState(todayInDhaka());
  const [tab, setTab] = useState<TabId>("sales");

  const rangeError = validateRange(from, to);

  return (
    <section className="max-w-6xl mx-auto space-y-5">
      <div>
        <h2 className="text-2xl font-bold text-strong">Reports</h2>
        <p className="text-sm text-text-secondary">
          Computed live from orders, deliveries, customers and expenses. Draft, cancelled and refunded orders are
          excluded from revenue, and days follow the branch timezone.
        </p>
      </div>

      <div className="card p-4 flex flex-wrap items-end gap-3">
        <div>
          <label htmlFor="report-from" className="block text-xs font-medium text-text-secondary mb-1.5">
            From
          </label>
          <input
            id="report-from"
            type="date"
            value={from}
            max={to || undefined}
            onChange={(e) => setFrom(e.target.value)}
            className="input-field"
          />
        </div>
        <div>
          <label htmlFor="report-to" className="block text-xs font-medium text-text-secondary mb-1.5">
            To
          </label>
          <input
            id="report-to"
            type="date"
            value={to}
            min={from || undefined}
            onChange={(e) => setTo(e.target.value)}
            className="input-field"
          />
        </div>
        {rangeError && (
          <p role="alert" className="text-sm text-danger pb-2.5">
            {rangeError}
          </p>
        )}
      </div>

      <div role="tablist" aria-label="Report type" className="flex flex-wrap gap-2">
        {TABS.map((t) => (
          <button
            key={t.id}
            role="tab"
            aria-selected={tab === t.id}
            onClick={() => setTab(t.id)}
            className={
              tab === t.id
                ? "bg-primary text-on-primary rounded-lg px-4 py-2 text-sm font-semibold"
                : "btn-secondary !py-2"
            }
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* Only the active tab is mounted, so only its report is requested. */}
      <div role="tabpanel">
        {tab === "sales" && <SalesTab from={from} to={to} enabled={!rangeError} />}
        {tab === "products" && <ProductsTab from={from} to={to} enabled={!rangeError} />}
        {tab === "categories" && <CategoriesTab from={from} to={to} enabled={!rangeError} />}
        {tab === "delivery" && <DeliveryTab from={from} to={to} enabled={!rangeError} />}
        {tab === "customers" && <CustomersTab from={from} to={to} enabled={!rangeError} />}
        {tab === "branches" && <BranchesTab from={from} to={to} enabled={!rangeError} />}
      </div>
    </section>
  );
}

type TabProps = { from: string; to: string; enabled: boolean };

// ---------- Tabs ----------

function SalesTab({ from, to, enabled }: TabProps) {
  const { report, isLoading, error } = useSalesReport(from, to, enabled);
  const [exporting, setExporting] = useState(false);
  const [exportError, setExportError] = useState<string | null>(null);

  async function handleExport() {
    setExporting(true);
    setExportError(null);
    try {
      await downloadSalesCsv(from, to);
    } catch (err) {
      setExportError(err instanceof ApiError ? err.message : "Couldn't export the CSV.");
    } finally {
      setExporting(false);
    }
  }

  return (
    <ReportState enabled={enabled} isLoading={isLoading} error={error}>
      {report && (
        <div className="space-y-5">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="text-sm text-text-secondary">
              {formatDate(report.from)} – {formatDate(report.to)}
            </div>
            <div className="flex items-center gap-3">
              {exportError && (
                <span role="alert" className="text-xs text-danger">
                  {exportError}
                </span>
              )}
              <button className="btn-secondary" onClick={handleExport} disabled={exporting}>
                {exporting ? "Exporting…" : "Export CSV"}
              </button>
            </div>
          </div>

          <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
            <StatCard label="Revenue" value={formatBDT(report.totals.revenue)} tone="success" />
            <StatCard label="Orders" value={String(report.totals.orders)} />
            <StatCard label="Average order value" value={formatBDT(report.totals.averageOrderValue)} />
            <StatCard label="Discounts given" value={formatBDT(report.totals.discounts)} />
            <StatCard label="Tax collected" value={formatBDT(report.totals.tax)} />
            <StatCard label="Delivery fees" value={formatBDT(report.totals.deliveryFees)} />
          </div>

          {report.totals.orders === 0 ? (
            <EmptyBlock text="No completed orders in this range." />
          ) : (
            <>
              <div className="card overflow-hidden">
                <h3 className="text-sm font-bold text-strong p-4 pb-2">By order source</h3>
                <table className="w-full text-sm">
                  <thead className="bg-surface-hover text-text-secondary">
                    <tr>
                      <th className="text-left p-3 font-medium">Source</th>
                      <th className="text-right p-3 font-medium">Orders</th>
                      <th className="text-right p-3 font-medium">Revenue</th>
                      <th className="p-3 w-40" aria-label="Share of revenue" />
                    </tr>
                  </thead>
                  <tbody>
                    {report.bySource.map((s) => (
                      <tr key={s.source} className="border-t border-border">
                        <td className="p-3 text-strong font-medium">{humanize(s.source)}</td>
                        <td className="p-3 text-right text-text-secondary">{s.orders}</td>
                        <td className="p-3 text-right text-strong">{formatBDT(s.revenue)}</td>
                        <td className="p-3">
                          <Bar value={s.revenue} max={report.totals.revenue} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <div className="card overflow-hidden">
                <h3 className="text-sm font-bold text-strong p-4 pb-2">By day</h3>
                <div className="max-h-96 overflow-auto">
                  <table className="w-full text-sm">
                    <thead className="bg-surface-hover text-text-secondary sticky top-0">
                      <tr>
                        <th className="text-left p-3 font-medium">Date</th>
                        <th className="text-right p-3 font-medium">Orders</th>
                        <th className="text-right p-3 font-medium">Revenue</th>
                        <th className="p-3 w-40" aria-label="Relative to the best day" />
                      </tr>
                    </thead>
                    <tbody>
                      {report.byDay.map((d) => (
                        <tr key={d.date} className={`border-t border-border ${d.orders === 0 ? "opacity-50" : ""}`}>
                          <td className="p-3 text-strong">{formatDate(d.date)}</td>
                          <td className="p-3 text-right text-text-secondary">{d.orders}</td>
                          <td className="p-3 text-right text-strong">{formatBDT(d.revenue)}</td>
                          <td className="p-3">
                            <Bar value={d.revenue} max={Math.max(...report.byDay.map((x) => x.revenue))} />
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </>
          )}
        </div>
      )}
    </ReportState>
  );
}

function ProductsTab({ from, to, enabled }: TabProps) {
  const { report, isLoading, error } = useProductsReport(from, to, enabled);
  const top = report?.products[0]?.revenue ?? 0;

  return (
    <ReportState enabled={enabled} isLoading={isLoading} error={error}>
      {report &&
        (report.products.length === 0 ? (
          <EmptyBlock text="No items were sold in this range." />
        ) : (
          <div className="card overflow-hidden">
            <h3 className="text-sm font-bold text-strong p-4 pb-2">Top products by revenue</h3>
            <table className="w-full text-sm">
              <thead className="bg-surface-hover text-text-secondary">
                <tr>
                  <th className="text-left p-3 font-medium w-12">#</th>
                  <th className="text-left p-3 font-medium">Item</th>
                  <th className="text-right p-3 font-medium">Qty sold</th>
                  <th className="text-right p-3 font-medium">Revenue</th>
                  <th className="p-3 w-40" aria-label="Relative to the top item" />
                </tr>
              </thead>
              <tbody>
                {report.products.map((p, i) => (
                  <tr key={p.name} className="border-t border-border">
                    <td className="p-3 text-text-secondary">{i + 1}</td>
                    <td className="p-3 text-strong font-medium">{p.name}</td>
                    <td className="p-3 text-right text-text-secondary">{p.quantity}</td>
                    <td className="p-3 text-right text-strong">{formatBDT(p.revenue)}</td>
                    <td className="p-3">
                      <Bar value={p.revenue} max={top} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))}
    </ReportState>
  );
}

function CategoriesTab({ from, to, enabled }: TabProps) {
  const { report, isLoading, error } = useCategoriesReport(from, to, enabled);
  const total = report?.categories.reduce((sum, c) => sum + c.revenue, 0) ?? 0;

  return (
    <ReportState enabled={enabled} isLoading={isLoading} error={error}>
      {report &&
        (report.categories.length === 0 ? (
          <EmptyBlock text="No items were sold in this range." />
        ) : (
          <div className="card overflow-hidden">
            <h3 className="text-sm font-bold text-strong p-4 pb-2">Revenue by menu category</h3>
            <table className="w-full text-sm">
              <thead className="bg-surface-hover text-text-secondary">
                <tr>
                  <th className="text-left p-3 font-medium">Category</th>
                  <th className="text-right p-3 font-medium">Qty sold</th>
                  <th className="text-right p-3 font-medium">Revenue</th>
                  <th className="text-right p-3 font-medium">Share</th>
                  <th className="p-3 w-40" aria-label="Share of item revenue" />
                </tr>
              </thead>
              <tbody>
                {report.categories.map((c) => (
                  <tr key={c.category} className="border-t border-border">
                    <td className="p-3 text-strong font-medium">{c.category}</td>
                    <td className="p-3 text-right text-text-secondary">{c.quantity}</td>
                    <td className="p-3 text-right text-strong">{formatBDT(c.revenue)}</td>
                    <td className="p-3 text-right text-text-secondary">
                      {total > 0 ? `${((c.revenue / total) * 100).toFixed(1)}%` : "—"}
                    </td>
                    <td className="p-3">
                      <Bar value={c.revenue} max={total} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <p className="text-xs text-text-muted p-3 border-t border-border">
              Based on item line totals, so order-level discounts, tax and delivery fees are not included.
            </p>
          </div>
        ))}
    </ReportState>
  );
}

function DeliveryTab({ from, to, enabled }: TabProps) {
  const { report, isLoading, error } = useDeliveryReport(from, to, enabled);

  return (
    <ReportState enabled={enabled} isLoading={isLoading} error={error}>
      {report && (
        <div className="space-y-5">
          <div className="grid grid-cols-2 lg:grid-cols-5 gap-4">
            <StatCard label="Delivered" value={String(report.totals.delivered)} tone="success" />
            <StatCard label="Failed" value={String(report.totals.failed)} tone="danger" />
            <StatCard label="In progress" value={String(report.totals.inProgress)} />
            <StatCard
              label="Success rate"
              value={report.totals.successRate === null ? "—" : `${report.totals.successRate}%`}
            />
            <StatCard
              label="Avg. delivery time"
              value={report.totals.avgDeliveryMinutes === null ? "—" : `${report.totals.avgDeliveryMinutes} min`}
            />
          </div>
          <p className="text-xs text-text-muted">
            Delivery time is measured from rider assignment to delivery. Deliveries are counted by the day the order
            was placed.
          </p>

          {report.byRider.length === 0 ? (
            <EmptyBlock text="No rider-assigned deliveries in this range." />
          ) : (
            <div className="card overflow-hidden">
              <h3 className="text-sm font-bold text-strong p-4 pb-2">By rider</h3>
              <table className="w-full text-sm">
                <thead className="bg-surface-hover text-text-secondary">
                  <tr>
                    <th className="text-left p-3 font-medium">Rider</th>
                    <th className="text-right p-3 font-medium">Assigned</th>
                    <th className="text-right p-3 font-medium">Delivered</th>
                    <th className="text-right p-3 font-medium">Failed</th>
                  </tr>
                </thead>
                <tbody>
                  {report.byRider.map((r) => (
                    <tr key={r.rider} className="border-t border-border">
                      <td className="p-3 text-strong font-medium">{r.rider}</td>
                      <td className="p-3 text-right text-text-secondary">{r.assigned}</td>
                      <td className="p-3 text-right text-success">{r.delivered}</td>
                      <td className="p-3 text-right text-danger">{r.failed}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </ReportState>
  );
}

function CustomersTab({ from, to, enabled }: TabProps) {
  const { report, isLoading, error } = useCustomersReport(from, to, enabled);

  return (
    <ReportState enabled={enabled} isLoading={isLoading} error={error}>
      {report && (
        <div className="space-y-5">
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <StatCard label="New customers" value={String(report.newCustomers)} />
            <StatCard label="Loyalty points earned" value={report.loyalty.pointsEarned.toLocaleString("en-BD")} tone="success" />
            <StatCard label="Loyalty points redeemed" value={report.loyalty.pointsRedeemed.toLocaleString("en-BD")} />
          </div>

          {report.topSpenders.length === 0 ? (
            <EmptyBlock text="No orders were linked to a customer in this range." />
          ) : (
            <div className="card overflow-hidden">
              <h3 className="text-sm font-bold text-strong p-4 pb-2">Top spenders</h3>
              <table className="w-full text-sm">
                <thead className="bg-surface-hover text-text-secondary">
                  <tr>
                    <th className="text-left p-3 font-medium w-12">#</th>
                    <th className="text-left p-3 font-medium">Customer</th>
                    <th className="text-left p-3 font-medium">Phone</th>
                    <th className="text-right p-3 font-medium">Orders</th>
                    <th className="text-right p-3 font-medium">Spent</th>
                  </tr>
                </thead>
                <tbody>
                  {report.topSpenders.map((c, i) => (
                    <tr key={c.customerId} className="border-t border-border">
                      <td className="p-3 text-text-secondary">{i + 1}</td>
                      <td className="p-3 text-strong font-medium">{c.name}</td>
                      <td className="p-3 text-text-secondary">{c.phone ?? "—"}</td>
                      <td className="p-3 text-right text-text-secondary">{c.orders}</td>
                      <td className="p-3 text-right text-strong">{formatBDT(c.spent)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <p className="text-xs text-text-muted p-3 border-t border-border">
                Spend within the selected range only. Walk-in orders with no customer attached are not listed.
              </p>
            </div>
          )}
        </div>
      )}
    </ReportState>
  );
}

function BranchesTab({ from, to, enabled }: TabProps) {
  const { report, isLoading, error } = useBranchesReport(from, to, enabled);
  const top = report ? Math.max(0, ...report.branches.map((b) => b.revenue)) : 0;

  return (
    <ReportState enabled={enabled} isLoading={isLoading} error={error}>
      {report &&
        (report.branches.length === 0 ? (
          <EmptyBlock text="No branches are available to compare." />
        ) : (
          <div className="card overflow-hidden">
            <h3 className="text-sm font-bold text-strong p-4 pb-2">Branches you have access to</h3>
            <table className="w-full text-sm">
              <thead className="bg-surface-hover text-text-secondary">
                <tr>
                  <th className="text-left p-3 font-medium">Branch</th>
                  <th className="text-right p-3 font-medium">Orders</th>
                  <th className="text-right p-3 font-medium">Revenue</th>
                  <th className="text-right p-3 font-medium">Expenses</th>
                  <th className="text-right p-3 font-medium">Net</th>
                  <th className="p-3 w-40" aria-label="Revenue relative to the top branch" />
                </tr>
              </thead>
              <tbody>
                {report.branches.map((b) => (
                  <tr key={b.branchId} className="border-t border-border">
                    <td className="p-3 text-strong font-medium">
                      {b.name} <span className="text-text-muted text-xs">{b.code}</span>
                    </td>
                    <td className="p-3 text-right text-text-secondary">{b.orders}</td>
                    <td className="p-3 text-right text-strong">{formatBDT(b.revenue)}</td>
                    <td className="p-3 text-right text-text-secondary">{formatBDT(b.expenses)}</td>
                    <td className={`p-3 text-right font-medium ${b.netProfit < 0 ? "text-danger" : "text-success"}`}>
                      {formatBDT(b.netProfit)}
                    </td>
                    <td className="p-3">
                      <Bar value={b.revenue} max={top} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <p className="text-xs text-text-muted p-3 border-t border-border">
              Net = revenue minus recorded expenses (not a full profit-and-loss; see Accounting).
            </p>
          </div>
        ))}
    </ReportState>
  );
}

// ---------- Shared pieces ----------

function ReportState({
  enabled,
  isLoading,
  error,
  children,
}: {
  enabled: boolean;
  isLoading: boolean;
  error: unknown;
  children: ReactNode;
}) {
  if (!enabled) {
    return <EmptyBlock text="Fix the date range above to load this report." />;
  }
  if (error) {
    return (
      <div role="alert" className="card p-10 text-center text-sm text-text-secondary">
        {error instanceof ApiError ? error.message : "Couldn’t load this report."}
      </div>
    );
  }
  if (isLoading) {
    return (
      <div className="space-y-3" aria-busy="true">
        <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
          {Array.from({ length: 3 }).map((_, i) => (
            <div key={i} className="card h-20 animate-pulse" />
          ))}
        </div>
        <div className="card h-48 animate-pulse" />
      </div>
    );
  }
  return <>{children}</>;
}

function EmptyBlock({ text }: { text: string }) {
  return <div className="card p-10 text-center text-sm text-text-secondary">{text}</div>;
}

function StatCard({ label, value, tone }: { label: string; value: string; tone?: "success" | "danger" }) {
  const toneClass = tone === "success" ? "text-success" : tone === "danger" ? "text-danger" : "text-strong";
  return (
    <div className="card p-4">
      <div className="text-sm text-text-secondary">{label}</div>
      <div className={`text-xl font-bold mt-1 ${toneClass}`}>{value}</div>
    </div>
  );
}

/** Proportional bar -- the number next to it is the real value; the bar is only a visual cue. */
function Bar({ value, max }: { value: number; max: number }) {
  const pct = max > 0 ? Math.max(0, Math.min(100, (value / max) * 100)) : 0;
  return (
    <div className="h-2 rounded-full bg-surface-hover" aria-hidden="true">
      <div className="h-2 rounded-full bg-primary" style={{ width: `${pct}%` }} />
    </div>
  );
}
