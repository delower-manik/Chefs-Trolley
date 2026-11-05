export type KitchenState = "new" | "accepted" | "preparing" | "ready" | "served" | "completed";

export type KitchenTicketItem = {
  id: string;
  name: string;
  quantity: number;
  modifiers: { id: string; name: string; price: number }[];
  notes: string | null;
  comboName?: string | null;
  stationId?: string | null;
  kitchenState?: KitchenState;
};

export type KitchenTicket = {
  id: string;
  code: string;
  source: string;
  tableLabel?: string | null;
  kitchenState: KitchenState;
  status: string;
  createdAt: string;
  items: KitchenTicketItem[];
};
