export type GatewayOption = { key: string; label: string; manual: boolean };

export type GatewaySessionStatus = "created" | "pending" | "paid" | "failed" | "cancelled" | "expired" | "review";

export type GatewaySession = {
  id: string;
  orderId: string;
  gateway: string;
  amount: number;
  status: GatewaySessionStatus;
  redirectUrl: string | null;
  qrPayload: string | null;
  transactionId: string | null;
  note: string | null;
  expiresAt: string | null;
  settledAt: string | null;
};
