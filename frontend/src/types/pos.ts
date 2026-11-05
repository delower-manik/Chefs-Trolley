// Phase 13 — POS completion: payments, discounts, refunds, cash register, printers, receipts.

export type PaymentMethod = "cash" | "card" | "mobile_banking" | "gift_card" | "other";

export type Payment = {
  id: string;
  orderId: string;
  kind: "payment" | "refund";
  method: PaymentMethod;
  reference: string | null;
  amount: number;
  tip: number;
  tendered: number | null;
  changeGiven: number | null;
  reason: string | null;
  refundsPaymentId: string | null;
  restocked: boolean;
  voidedAt: string | null;
  voidReason: string | null;
  createdAt: string;
};

export type PaymentSummary = {
  grandTotal: number;
  paid: number;
  refunded: number;
  net: number;
  balanceDue: number;
  tips: number;
  paymentState: string;
};

export type PaymentsResponse = { data: Payment[]; summary: PaymentSummary };

export type TakePaymentPayload = { method: PaymentMethod; amount?: number; tip?: number; tendered?: number; reference?: string; gift_card_code?: string };
export type RefundPayload = { amount: number; reason: string; method?: PaymentMethod; refunds_payment_id?: string; restock?: boolean };
export type DiscountPayload = { type: "percent" | "fixed"; value: number; reason: string };

export type CashShift = {
  id: string;
  branchId: string;
  status: "open" | "closed";
  openedBy: string;
  openingFloat: number;
  openedAt: string;
  closedAt: string | null;
  expectedCash: number | null;
  countedCash: number | null;
  variance: number | null;
  notes: string | null;
  byMethod?: { method: PaymentMethod; payments: number; tips: number; refunds: number; net: number; count: number }[];
  voidedPayments?: number;
  movements?: { id: string; type: "in" | "out"; amount: number; reason: string; createdAt: string }[];
  expectedNow?: number;
};

export type Printer = {
  id: string;
  branchId: string;
  name: string;
  connection: "network" | "usb" | "browser";
  role: "receipt" | "kitchen";
  host: string | null;
  port: number;
  kitchenStationId: string | null;
  paperWidth: 58 | 80;
  opensDrawer: boolean;
  isActive: boolean;
};

export type SavePrinterPayload = Partial<{
  name: string;
  connection: Printer["connection"];
  role: Printer["role"];
  host: string | null;
  port: number | null;
  kitchen_station_id: string | null;
  paper_width: 58 | 80;
  opens_drawer: boolean;
  is_active: boolean;
}>;

export type ReceiptDoc = {
  business: string | null;
  code: string;
  date: string | null;
  source: string;
  table: string | null;
  items: { name: string; quantity: number; total: number; modifiers: string[]; notes: string | null }[];
  subtotal: number;
  discount: number;
  tax: number;
  taxIncluded: number;
  deliveryFee: number;
  grandTotal: number;
  payments: { method: PaymentMethod; amount: number; tip: number; tendered: number | null; change: number | null }[];
  refunded: number;
  balanceDue: number;
  footer: string;
  /** Added in Phase 17; optional so an older API still renders. */
  locale?: "en" | "bn";
  labels?: Record<string, string | Record<string, string>>;
  currencySymbol?: string;
  legalName?: string | null;
  address?: string | null;
  phone?: string | null;
  taxId?: string | null;
  header?: string | null;
};

export type PrintResult = { delivery: "server" | "client"; escposBase64: string | null; note: string | null };
