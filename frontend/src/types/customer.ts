export type Customer = {
  id: string;
  branchId: string | null;
  name: string;
  phone: string;
  email: string | null;
  address: string | null;
  notes: string | null;
  loyaltyPoints: number;
  isActive: boolean;
  marketingOptOut: boolean;
};

export type CustomerOrderSummary = {
  id: string;
  code: string;
  status: string;
  grandTotal: number;
  createdAt: string;
};

export type LoyaltyEntryType = "earned" | "redeemed" | "adjusted";

export type LoyaltyLedgerEntry = {
  id: string;
  type: LoyaltyEntryType;
  pointsChange: number;
  balanceAfter: number;
  reason: string | null;
  createdAt: string;
};

export type SaveCustomerPayload = {
  branch_id?: string;
  name: string;
  phone: string;
  email?: string;
  address?: string;
  notes?: string;
  is_active?: boolean;
  marketing_opt_out?: boolean;
};

export type LoyaltyAdjustmentPayload = {
  points_change: number;
  reason: string;
};
