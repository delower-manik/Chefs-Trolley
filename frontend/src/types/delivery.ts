export type VehicleType = "bicycle" | "motorbike" | "car" | "on_foot";
export type RiderStatus = "available" | "busy" | "offline";

export type Rider = {
  id: string;
  branchId: string;
  name: string;
  phone: string;
  vehicleType: VehicleType;
  status: RiderStatus;
  isActive: boolean;
  userId?: string | null;
};

export type DeliveryZone = {
  id: string;
  branchId: string;
  name: string;
  deliveryFee: number;
  isActive: boolean;
};

export type DeliveryStatus = "pending" | "assigned" | "picked_up" | "delivered" | "failed";

export type DeliveryAssignment = {
  id: string;
  orderId: string;
  orderCode: string | null;
  orderGrandTotal: number | null;
  branchId: string;
  riderId: string | null;
  riderName: string | null;
  zoneId: string | null;
  zoneName: string | null;
  deliveryAddress: string | null;
  status: DeliveryStatus;
  assignedAt: string | null;
  pickedUpAt: string | null;
  deliveredAt: string | null;
  failedAt: string | null;
  failureReason: string | null;
  recipientName: string | null;
  proofNote: string | null;
  createdAt: string;
};

export type SaveRiderPayload = {
  name: string;
  phone: string;
  vehicle_type?: VehicleType;
  status?: RiderStatus;
  is_active?: boolean;
  user_id?: string | null;
};

export type SaveDeliveryZonePayload = {
  name: string;
  delivery_fee: number;
  is_active?: boolean;
};

export type AssignDeliveryPayload = {
  rider_id: string;
  delivery_zone_id?: string;
  delivery_address?: string;
};

export type DeliverOrderPayload = {
  recipient_name?: string;
  proof_note?: string;
};
