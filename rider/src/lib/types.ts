export type Profile = { id: string; name: string; phone: string; vehicleType: string; status: "available" | "busy" | "offline"; locationSharing: boolean; openDeliveries: number; cashHolding: number };
export type Delivery = {
  id: string; status: "assigned" | "picked_up" | "delivered" | "failed"; orderCode: string; orderStatus: string; cancelled: boolean;
  address: string | null; zone: string | null; mapUrl: string | null; customerName: string | null; customerPhone: string | null;
  items: { name: string; quantity: number; notes: string | null }[]; cashToCollect: number; total: number | null;
  assignedAt: string | null; pickedUpAt: string | null; deliveredAt: string | null; failedAt: string | null; failureReason: string | null;
};
export type Cash = { holding: number; items: { orderCode: string | null; amount: number; collectedAt: string }[] };
export type Tokens = { accessToken: string; refreshToken: string; expiresAt: string };
export type LoginResult = { user: { name: string }; tokens: Tokens } | { mfaRequired: true; challenge: string };
