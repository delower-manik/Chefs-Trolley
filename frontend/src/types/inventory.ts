export type Unit = {
  id: string;
  name: string;
  abbreviation: string;
};

export type Warehouse = {
  id: string;
  branchId: string;
  name: string;
  isDefault: boolean;
};

export type Ingredient = {
  id: string;
  branchId: string;
  name: string;
  unitId: string;
  unitAbbreviation: string | null;
  currentStock: number;
  reorderLevel: number;
  costPerUnit: number;
  isLowStock: boolean;
  isActive: boolean;
};

export type Supplier = {
  id: string;
  branchId: string | null;
  name: string;
  contactPerson: string | null;
  phone: string | null;
  email: string | null;
  address: string | null;
  isActive: boolean;
};

export type PurchaseItem = {
  id: string;
  ingredientId: string;
  ingredientName: string | null;
  quantity: number;
  unitCost: number;
  totalCost: number;
};

export type PurchaseStatus = "draft" | "received" | "cancelled";

export type Purchase = {
  id: string;
  branchId: string;
  supplierId: string;
  supplierName: string | null;
  warehouseId: string;
  warehouseName: string | null;
  status: PurchaseStatus;
  totalCost: number;
  receivedAt: string | null;
  items: PurchaseItem[];
  createdAt: string;
};

export type StockLedgerType =
  | "purchase"
  | "adjustment"
  | "waste"
  | "sale_consumption"
  | "correction"
  | "transfer_in"
  | "transfer_out";

export type StockLedgerEntry = {
  id: string;
  ingredientId: string;
  ingredientName: string | null;
  warehouseId: string;
  warehouseName: string | null;
  type: StockLedgerType;
  quantityChange: number;
  balanceAfter: number;
  reason: string | null;
  referenceType: string | null;
  createdAt: string;
};

export type StockTransferLine = {
  id: string;
  ingredientId: string;
  ingredientName: string | null;
  quantity: number;
};

export type StockTransfer = {
  id: string;
  branchId: string;
  fromWarehouseId: string;
  fromWarehouseName: string | null;
  toWarehouseId: string;
  toWarehouseName: string | null;
  items: StockTransferLine[];
  createdAt: string;
};

export type RecipeLine = {
  id: string;
  ingredientId: string;
  ingredientName: string | null;
  unitAbbreviation: string | null;
  quantityRequired: number;
};

export type Recipe = {
  foodItemId: string;
  foodItemName: string;
  ingredients: RecipeLine[];
};

export type SaveWarehousePayload = {
  name: string;
  is_default?: boolean;
};

export type SaveIngredientPayload = {
  branch_id: string;
  unit_id: string;
  name: string;
  reorder_level?: number;
  cost_per_unit?: number;
  is_active?: boolean;
};

export type SaveSupplierPayload = {
  branch_id?: string;
  name: string;
  contact_person?: string;
  phone?: string;
  email?: string;
  address?: string;
};

export type CreatePurchasePayload = {
  supplier_id: string;
  warehouse_id: string;
  items: { ingredient_id: string; quantity: number; unit_cost: number }[];
};

export type StockAdjustmentPayload = {
  ingredient_id: string;
  warehouse_id: string;
  quantity_change: number;
  type: "adjustment" | "waste";
  reason?: string;
};

export type CreateStockTransferPayload = {
  from_warehouse_id: string;
  to_warehouse_id: string;
  items: { ingredient_id: string; quantity: number }[];
};

export type SaveRecipePayload = {
  ingredients: { ingredient_id: string; quantity_required: number }[];
};
