"use client";

import { useParams, useRouter } from "next/navigation";
import { useOrder } from "@/hooks/use-orders";
import { StatusBadge } from "@/components/orders/status-badge";
import { formatBDT, formatDateTime } from "@/lib/format";
import { Can } from "@/components/ui/can";
import { apiRequest, ApiError } from "@/lib/api/client";
import { useState } from "react";
import Link from "next/link";
import { PaymentPanel } from "@/components/orders/payment-panel";

export default function OrderDetailPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { order, isLoading, error, refresh } = useOrder(id);
  const [cancelling, setCancelling] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  async function handleCancel() {
    if (!order) return;
    if (!window.confirm(`Cancel order ${order.code}? This cannot be undone.`)) return;
    setCancelling(true);
    setActionError(null);
    try {
      await apiRequest(`/orders/${order.id}/cancel`, { method: "POST" });
      await refresh();
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : "Couldn't cancel the order. Try again.");
    } finally {
      setCancelling(false);
    }
  }

  if (error) {
    return (
      <div className="card p-8 text-center max-w-md mx-auto mt-12">
        <span className="material-symbols-outlined text-danger text-4xl">error</span>
        <h2 className="font-bold text-strong mt-3">Order not found</h2>
        <p className="text-sm text-text-secondary mt-1">
          This order may have been removed, or the ID in the URL is incorrect.
        </p>
      </div>
    );
  }

  if (isLoading || !order) {
    return (
      <div className="max-w-4xl mx-auto space-y-4">
        <div className="h-8 w-48 bg-surface-hover rounded animate-pulse" />
        <div className="h-64 bg-surface-hover rounded animate-pulse" />
      </div>
    );
  }

  return (
    <section className="max-w-4xl mx-auto space-y-5">
      <div className="flex items-start justify-between">
        <div>
          <div className="flex items-center gap-3">
            <h2 className="text-2xl font-bold text-strong">{order.code}</h2>
            <StatusBadge status={order.status} />
          </div>
          <p className="text-sm text-text-secondary mt-1">
            {order.branchName} · Placed {formatDateTime(order.createdAt)}
          </p>
        </div>

        <div className="flex items-center gap-3">
        <Link href={`/orders/${order.id}/receipt`} className="btn-secondary text-sm">Receipt / Print</Link>
        <Can permission="orders.cancel">
          {!["completed", "cancelled", "refunded", "delivered"].includes(order.status) && (
            <button onClick={handleCancel} disabled={cancelling} className="btn-secondary text-danger">
              {cancelling ? "Cancelling..." : "Cancel order"}
            </button>
          )}
        </Can>
        </div>
      </div>

      {actionError && (
        <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2" role="alert">
          {actionError}
        </div>
      )}

      <div className="card overflow-hidden">
        <div className="p-4 border-b border-border font-bold text-strong">Items</div>
        <table className="w-full text-sm">
          <thead className="bg-surface-hover text-text-secondary">
            <tr>
              <th className="text-left p-3 font-medium">Item</th>
              <th className="text-right p-3 font-medium">Qty</th>
              <th className="text-right p-3 font-medium">Unit price</th>
              <th className="text-right p-3 font-medium">Total</th>
            </tr>
          </thead>
          <tbody>
            {order.items.map((item) => (
              <tr key={item.id} className="border-t border-border">
                <td className="p-3">
                  {item.name}
                  {item.modifiers.length > 0 && (
                    <div className="text-xs text-text-secondary mt-0.5">
                      {item.modifiers.map((m) => m.name).join(", ")}
                    </div>
                  )}
                </td>
                <td className="p-3 text-right text-text-secondary">{item.quantity}</td>
                <td className="p-3 text-right text-text-secondary">{formatBDT(item.unitPrice)}</td>
                <td className="p-3 text-right font-medium text-strong">{formatBDT(item.total)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="card p-5 space-y-2 text-sm">
        <Row label="Subtotal" value={formatBDT(order.subtotal)} />
        <Row label="Tax" value={formatBDT(order.taxTotal)} />
        <Row label={order.discountReason ? `Discount (${order.discountReason})` : "Discount"} value={`-${formatBDT(order.discountTotal)}`} />
        <Row label="Delivery fee" value={formatBDT(order.deliveryFee)} />
        <div className="border-t border-border pt-2 flex justify-between font-bold text-strong">
          <span>Grand total</span>
          <span>{formatBDT(order.grandTotal)}</span>
        </div>
      </div>

      <PaymentPanel order={order} onChanged={refresh} />
    </section>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between text-text-secondary">
      <span>{label}</span>
      <span>{value}</span>
    </div>
  );
}
