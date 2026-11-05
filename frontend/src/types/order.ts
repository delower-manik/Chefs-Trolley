// Mirrors 01_CHEFS_TROLLEY_FULL_SYSTEM_ARCHITECTURE.md §7 — Order Domain.

export type OrderStatus =
  | "draft"
  | "pending"
  | "confirmed"
  | "accepted"
  | "preparing"
  | "ready"
  | "assigned"
  | "picked_up"
  | "delivered"
  | "completed"
  | "cancelled"
  | "refunded";

export type OrderType = "dine_in" | "takeaway" | "delivery" | "scheduled" | "pos" | "app_web";

export type PaymentState = "unpaid" | "authorized" | "paid" | "partially_refunded" | "refunded" | "failed";

export type OrderItem = {
  id: string;
  foodItemId: string;
  name: string;
  quantity: number;
  unitPrice: number;
  modifiers: { id: string; name: string; price: number }[];
  total: number;
  notes?: string;
};

export type Order = {
  id: string;
  code: string; // human-readable order reference, e.g. CT-1048
  source: OrderType;
  branchId: string;
  branchName: string;
  customerId: string | null;
  customerName: string | null;
  items: OrderItem[];
  subtotal: number;
  taxTotal: number;
  discountTotal: number;
  manualDiscountTotal?: number;
  discountReason?: string | null;
  amountPaid?: number;
  balanceDue?: number;
  deliveryFee: number;
  grandTotal: number;
  taxInclusiveTotal?: number;
  diningTableId?: string | null;
  paymentState: PaymentState;
  kitchenState: "new" | "accepted" | "preparing" | "ready" | "served" | "completed";
  status: OrderStatus;
  createdAt: string;
  updatedAt: string;
};

export type PaginatedResponse<T> = {
  data: T[];
  meta: {
    currentPage: number;
    perPage: number;
    total: number;
    lastPage: number;
  };
};

export type CreateOrderItemPayload = {
  food_item_id?: string;
  combo_id?: string;
  quantity: number;
  modifier_ids?: string[];
  notes?: string;
};

export type CreateOrderPayload = {
  source: OrderType;
  dining_table_id?: string;
  customer_id?: string;
  promo_code?: string;
  discount_total?: number;
  delivery_fee?: number;
  delivery_zone_id?: string;
  delivery_address?: string;
  hold?: boolean;
  items: CreateOrderItemPayload[];
};

export type OrderFilters = {
  status?: OrderStatus;
  type?: OrderType;
  branchId?: string;
  search?: string;
  page?: number;
  perPage?: number;
};
