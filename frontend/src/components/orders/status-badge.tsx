import type { OrderStatus } from "@/types/order";

const STATUS_STYLES: Record<OrderStatus, string> = {
  draft: "bg-surface-hover text-text-secondary",
  pending: "bg-warning/15 text-warning",
  confirmed: "bg-primary/15 text-primary-fg",
  accepted: "bg-primary/15 text-primary-fg",
  preparing: "bg-warning/15 text-warning",
  ready: "bg-success/15 text-success",
  assigned: "bg-success/15 text-success",
  picked_up: "bg-success/15 text-success",
  delivered: "bg-success/15 text-success",
  completed: "bg-success/15 text-success",
  cancelled: "bg-danger/15 text-danger",
  refunded: "bg-danger/15 text-danger",
};

const STATUS_LABELS: Record<OrderStatus, string> = {
  draft: "Draft",
  pending: "Pending",
  confirmed: "Confirmed",
  accepted: "Accepted",
  preparing: "Preparing",
  ready: "Ready",
  assigned: "Assigned",
  picked_up: "Picked up",
  delivered: "Delivered",
  completed: "Completed",
  cancelled: "Cancelled",
  refunded: "Refunded",
};

export function StatusBadge({ status }: { status: OrderStatus }) {
  return <span className={`badge ${STATUS_STYLES[status]}`}>{STATUS_LABELS[status]}</span>;
}
