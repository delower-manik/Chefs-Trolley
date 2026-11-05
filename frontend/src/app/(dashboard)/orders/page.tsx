"use client";

import { useState } from "react";
import Link from "next/link";
import { useOrders } from "@/hooks/use-orders";
import { StatusBadge } from "@/components/orders/status-badge";
import { formatBDT, formatDateTime } from "@/lib/format";
import type { OrderFilters, OrderStatus } from "@/types/order";

const STATUS_OPTIONS: { value: OrderStatus | ""; label: string }[] = [
  { value: "", label: "All statuses" },
  { value: "pending", label: "Pending" },
  { value: "confirmed", label: "Confirmed" },
  { value: "preparing", label: "Preparing" },
  { value: "ready", label: "Ready" },
  { value: "completed", label: "Completed" },
  { value: "cancelled", label: "Cancelled" },
];

export default function OrdersPage() {
  const [filters, setFilters] = useState<OrderFilters>({ page: 1, perPage: 20 });
  const { orders, meta, isLoading, error } = useOrders(filters);

  return (
    <section className="max-w-7xl mx-auto space-y-5">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h2 className="text-2xl font-bold text-strong">Orders</h2>
          <p className="text-sm text-text-secondary">All dine-in, takeaway and delivery orders across branches.</p>
        </div>
        {/* Order creation happens through POS (Phase 3) once it ships; this list
            view intentionally does not link to a "New order" screen that
            does not exist yet. */}
      </div>

      <div className="card p-4 flex flex-col sm:flex-row gap-3">
        <input
          className="input-field sm:max-w-xs"
          placeholder="Search by order code or customer..."
          onChange={(e) => setFilters((f) => ({ ...f, search: e.target.value, page: 1 }))}
        />
        <select
          aria-label="Filter by status"
          className="input-field sm:max-w-[200px]"
          onChange={(e) =>
            setFilters((f) => ({ ...f, status: (e.target.value || undefined) as OrderStatus | undefined, page: 1 }))
          }
        >
          {STATUS_OPTIONS.map((opt) => (
            <option key={opt.value} value={opt.value}>
              {opt.label}
            </option>
          ))}
        </select>
      </div>

      <div className="card overflow-hidden">
        {error ? (
          <div className="p-10 text-center">
            <span className="material-symbols-outlined text-danger text-3xl">error</span>
            <p className="text-sm text-text-secondary mt-2">Couldn&apos;t load orders. Try refreshing the page.</p>
          </div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 6 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : orders.length === 0 ? (
          <div className="p-10 text-center">
            <span className="material-symbols-outlined text-text-muted text-3xl">receipt_long</span>
            <p className="text-sm text-text-secondary mt-2">No orders match these filters yet.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-surface-hover text-text-secondary">
                <tr>
                  <th className="text-left p-4 font-medium">Order</th>
                  <th className="text-left p-4 font-medium">Branch</th>
                  <th className="text-left p-4 font-medium">Type</th>
                  <th className="text-left p-4 font-medium">Status</th>
                  <th className="text-left p-4 font-medium">Placed</th>
                  <th className="text-right p-4 font-medium">Total</th>
                </tr>
              </thead>
              <tbody>
                {orders.map((order) => (
                  <tr key={order.id} className="border-t border-border hover:bg-surface-hover/50">
                    <td className="p-4">
                      <Link href={`/orders/${order.id}`} className="text-primary-fg font-medium">
                        {order.code}
                      </Link>
                    </td>
                    <td className="p-4 text-text-secondary">{order.branchName}</td>
                    <td className="p-4 text-text-secondary capitalize">{order.source.replace("_", " ")}</td>
                    <td className="p-4">
                      <StatusBadge status={order.status} />
                    </td>
                    <td className="p-4 text-text-secondary">{formatDateTime(order.createdAt)}</td>
                    <td className="p-4 text-right font-medium text-strong">{formatBDT(order.grandTotal)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {meta && meta.lastPage > 1 && (
          <div className="p-4 border-t border-border flex items-center justify-between text-sm">
            <span className="text-text-secondary">
              Page {meta.currentPage} of {meta.lastPage} · {meta.total} orders
            </span>
            <div className="flex gap-2">
              <button
                className="btn-secondary px-3 py-1.5"
                disabled={meta.currentPage <= 1}
                onClick={() => setFilters((f) => ({ ...f, page: (f.page ?? 1) - 1 }))}
              >
                Previous
              </button>
              <button
                className="btn-secondary px-3 py-1.5"
                disabled={meta.currentPage >= meta.lastPage}
                onClick={() => setFilters((f) => ({ ...f, page: (f.page ?? 1) + 1 }))}
              >
                Next
              </button>
            </div>
          </div>
        )}
      </div>
    </section>
  );
}
