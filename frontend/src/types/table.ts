export type TableStatus = "available" | "occupied" | "reserved" | "out_of_service";

export type DiningTable = {
  id: string;
  branchId: string;
  label: string;
  capacity: number;
  status: TableStatus;
  posX: number;
  posY: number;
};

export type ReservationStatus = "pending" | "confirmed" | "seated" | "completed" | "cancelled" | "no_show";

export type Reservation = {
  id: string;
  branchId: string;
  tableId: string | null;
  tableLabel: string | null;
  customerName: string;
  customerPhone: string;
  partySize: number;
  reservedFor: string;
  durationMinutes: number;
  status: ReservationStatus;
  notes: string | null;
};

export type SaveTablePayload = {
  branch_id: string;
  label: string;
  capacity: number;
  status?: TableStatus;
};

export type SaveReservationPayload = {
  branch_id: string;
  dining_table_id?: string;
  customer_name: string;
  customer_phone: string;
  party_size: number;
  reserved_for: string;
  duration_minutes?: number;
  notes?: string;
};
