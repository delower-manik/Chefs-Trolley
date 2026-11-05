"use client";

import { useState } from "react";
import { useDeliveries, assignDelivery, pickupDelivery, deliverOrder, failDelivery, useRiders } from "@/hooks/use-delivery";
import { formatBDT, formatDateTime } from "@/lib/format";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";
import type { DeliveryAssignment, DeliveryStatus } from "@/types/delivery";

const COLUMNS: { status: DeliveryStatus; label: string }[] = [
  { status: "pending", label: "Awaiting rider" },
  { status: "assigned", label: "Assigned" },
  { status: "picked_up", label: "Out for delivery" },
  { status: "delivered", label: "Delivered" },
];

export default function DeliveryPage() {
  const { deliveries, isLoading, error, refresh } = useDeliveries();
  const { riders } = useRiders();

  const failedCount = deliveries.filter((d) => d.status === "failed").length;

  if (error) {
    return (
      <div className="card p-8 text-center max-w-md mx-auto mt-12">
        <span className="material-symbols-outlined text-danger text-4xl">error</span>
        <h2 className="font-bold text-strong mt-3">Couldn&apos;t load the dispatch board</h2>
      </div>
    );
  }

  return (
    <section className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Delivery Dispatch</h2>
          <p className="text-sm text-text-secondary">
            Live view of every delivery order · auto-refreshing
            {failedCount > 0 && <span className="text-danger"> · {failedCount} failed attempt{failedCount > 1 ? "s" : ""} need attention</span>}
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        {COLUMNS.map((col) => {
          const items = deliveries.filter((d) => d.status === col.status);
          return (
            <div key={col.status} className="flex flex-col min-h-0">
              <div className="flex items-center justify-between px-1 pb-2">
                <h3 className="font-bold text-strong text-sm uppercase tracking-wide">{col.label}</h3>
                <span className="badge bg-surface-hover text-text-secondary">{items.length}</span>
              </div>
              <div className="space-y-3">
                {isLoading && items.length === 0 ? (
                  <div className="h-24 bg-surface-hover rounded-card animate-pulse" />
                ) : items.length === 0 ? (
                  <div className="text-xs text-text-muted text-center py-6">Nothing here</div>
                ) : (
                  items.map((d) => <DeliveryCard key={d.id} delivery={d} riders={riders} onChanged={refresh} />)
                )}
              </div>
            </div>
          );
        })}
      </div>

      {deliveries.some((d) => d.status === "failed") && (
        <div>
          <h3 className="font-bold text-strong text-sm uppercase tracking-wide mb-2">Failed attempts</h3>
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            {deliveries
              .filter((d) => d.status === "failed")
              .map((d) => (
                <DeliveryCard key={d.id} delivery={d} riders={riders} onChanged={refresh} />
              ))}
          </div>
        </div>
      )}
    </section>
  );
}

function DeliveryCard({
  delivery,
  riders,
  onChanged,
}: {
  delivery: DeliveryAssignment;
  riders: { id: string; name: string; status: string; isActive: boolean }[];
  onChanged: () => void;
}) {
  const [riderPicker, setRiderPicker] = useState(false);
  const [busy, setBusy] = useState(false);

  const availableRiders = riders.filter((r) => r.isActive);

  async function handleAssign(riderId: string) {
    setBusy(true);
    try {
      await assignDelivery(delivery.orderId, { rider_id: riderId });
      setRiderPicker(false);
      onChanged();
    } catch (err) {
      window.alert(err instanceof ApiError ? err.message : "Couldn't assign this rider.");
    } finally {
      setBusy(false);
    }
  }

  async function handlePickup() {
    setBusy(true);
    try {
      await pickupDelivery(delivery.orderId);
      onChanged();
    } catch (err) {
      window.alert(err instanceof ApiError ? err.message : "Couldn't update this delivery.");
    } finally {
      setBusy(false);
    }
  }

  async function handleDeliver() {
    setBusy(true);
    try {
      await deliverOrder(delivery.orderId, {});
      onChanged();
    } catch (err) {
      window.alert(err instanceof ApiError ? err.message : "Couldn't mark this delivered.");
    } finally {
      setBusy(false);
    }
  }

  async function handleFail(reason: string) {
    setBusy(true);
    try {
      await failDelivery(delivery.orderId, reason);
      onChanged();
    } catch (err) {
      window.alert(err instanceof ApiError ? err.message : "Couldn't record this failure.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className={`card p-3 border-l-2 ${delivery.status === "failed" ? "border-l-danger" : "border-l-primary"}`}>
      <div className="flex justify-between items-start mb-1">
        <span className="font-bold text-strong text-sm">{delivery.orderCode}</span>
        {delivery.orderGrandTotal !== null && <span className="text-xs text-text-secondary">{formatBDT(delivery.orderGrandTotal)}</span>}
      </div>
      {delivery.deliveryAddress && <div className="text-xs text-text-secondary mb-1">{delivery.deliveryAddress}</div>}
      {delivery.zoneName && <div className="text-xs text-text-muted mb-1">Zone: {delivery.zoneName}</div>}
      {delivery.riderName && <div className="text-xs text-primary-fg mb-2">Rider: {delivery.riderName}</div>}
      {delivery.status === "failed" && (
        <div className="text-xs text-danger mb-2">Failed: {delivery.failureReason}</div>
      )}
      {delivery.status === "delivered" && delivery.deliveredAt && (
        <div className="text-xs text-success mb-2">Delivered {formatDateTime(delivery.deliveredAt)}</div>
      )}

      <Can permission="delivery.manage">
        {(delivery.status === "pending" || delivery.status === "failed") && (
          <>
            {riderPicker ? (
              <select
                autoFocus
                disabled={busy}
                className="input-field text-xs py-1"
                onChange={(e) => e.target.value && handleAssign(e.target.value)}
                defaultValue=""
              >
                <option value="" disabled>
                  Choose a rider...
                </option>
                {availableRiders.map((r) => (
                  <option key={r.id} value={r.id}>
                    {r.name} {r.status === "busy" ? "(busy)" : ""}
                  </option>
                ))}
              </select>
            ) : (
              <button onClick={() => setRiderPicker(true)} className="btn-primary w-full py-1.5 text-xs">
                Assign rider
              </button>
            )}
          </>
        )}

        {delivery.status === "assigned" && (
          <div className="flex gap-2">
            <button onClick={handlePickup} disabled={busy} className="btn-primary flex-1 py-1.5 text-xs">
              Picked up
            </button>
            <button
              onClick={() => {
                const reason = window.prompt("Why did this delivery fail?");
                if (reason) handleFail(reason);
              }}
              disabled={busy}
              className="btn-secondary text-danger py-1.5 px-2 text-xs"
            >
              Fail
            </button>
          </div>
        )}

        {delivery.status === "picked_up" && (
          <div className="flex gap-2">
            <button onClick={handleDeliver} disabled={busy} className="btn-primary flex-1 py-1.5 text-xs">
              Mark delivered
            </button>
            <button
              onClick={() => {
                const reason = window.prompt("Why did this delivery fail?");
                if (reason) handleFail(reason);
              }}
              disabled={busy}
              className="btn-secondary text-danger py-1.5 px-2 text-xs"
            >
              Fail
            </button>
          </div>
        )}
      </Can>
    </div>
  );
}
