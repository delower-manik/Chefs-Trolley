// Phase 12 — Restaurant Core: tax, kitchen stations, floor plans, combos, waiter tabs.

export type TaxRate = {
  id: string;
  branchId: string;
  name: string;
  rate: number;
  isInclusive: boolean;
  isDefault: boolean;
  isActive: boolean;
};

export type SaveTaxRatePayload = {
  name?: string;
  rate?: number;
  is_inclusive?: boolean;
  is_default?: boolean;
  is_active?: boolean;
};

export type KitchenStation = {
  id: string;
  branchId: string;
  name: string;
  sortOrder: number;
  isActive: boolean;
  foodItemCount: number | null;
};

export type SaveStationPayload = { name?: string; sort_order?: number; is_active?: boolean };

export type TableShape = "rect" | "round";

export type FloorTable = {
  id: string;
  label: string;
  capacity: number;
  status: "available" | "occupied" | "reserved" | "out_of_service";
  posX: number;
  posY: number;
  width: number;
  height: number;
  shape: TableShape;
};

export type FloorPlan = {
  id: string;
  branchId: string;
  name: string;
  width: number;
  height: number;
  sortOrder: number;
  isActive: boolean;
  tables: FloorTable[];
};

export type SaveFloorPlanPayload = { name?: string; width?: number; height?: number; sort_order?: number; is_active?: boolean };

export type LayoutEntry = { id: string; pos_x: number; pos_y: number; width?: number; height?: number; shape?: TableShape };

export type Combo = {
  id: string;
  branchId: string;
  name: string;
  description: string | null;
  price: number;
  regularPrice: number;
  startsAt: string | null;
  endsAt: string | null;
  isActive: boolean;
  isOrderableNow: boolean;
  items: { foodItemId: string; name: string | null; quantity: number }[];
};

export type SaveComboPayload = {
  name?: string;
  description?: string | null;
  price?: number;
  starts_at?: string | null;
  ends_at?: string | null;
  is_active?: boolean;
  items?: { food_item_id: string; quantity: number }[];
};

/** Everything an order-taking screen needs, from GET /pos/catalog (needs only orders.create). */
export type CatalogModifier = { id: string; name: string; price: number };
export type CatalogItem = {
  id: string;
  categoryId: string;
  name: string;
  description: string | null;
  basePrice: number;
  imagePath: string | null;
  modifierGroups: {
    id: string;
    name: string;
    isRequired: boolean;
    minSelect: number;
    maxSelect: number;
    modifiers: CatalogModifier[];
  }[];
};
export type CatalogCombo = {
  id: string;
  name: string;
  description: string | null;
  price: number;
  items: { name: string | null; quantity: number }[];
};
export type CatalogTable = {
  id: string;
  label: string;
  capacity: number;
  status: FloorTable["status"];
  floorPlanId: string | null;
  posX: number;
  posY: number;
  width: number;
  height: number;
  shape: TableShape;
  openOrder: { id: string; code: string; grandTotal: number } | null;
};
export type PosCatalog = {
  categories: { id: string; name: string }[];
  items: CatalogItem[];
  combos: CatalogCombo[];
  floorPlans: { id: string; name: string; width: number; height: number }[];
  tables: CatalogTable[];
};

export type OrderLineInput = {
  food_item_id?: string;
  combo_id?: string;
  quantity: number;
  modifier_ids?: string[];
  notes?: string;
};
