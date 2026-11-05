export type Modifier = {
  id: string;
  name: string;
  price: number;
  isActive: boolean;
};

export type ModifierGroup = {
  id: string;
  name: string;
  isRequired: boolean;
  minSelect: number;
  maxSelect: number;
  modifiers: Modifier[];
};

export type Category = {
  id: string;
  branchId: string | null;
  name: string;
  slug: string;
  description: string | null;
  sortOrder: number;
  isActive: boolean;
  foodItemCount: number | null;
};

export type FoodItem = {
  id: string;
  categoryId: string;
  categoryName: string | null;
  branchId: string | null;
  name: string;
  slug: string;
  description: string | null;
  basePrice: number;
  taxRate: number;
  taxRateId?: string | null;
  kitchenStationId?: string | null;
  imagePath: string | null;
  isAvailable: boolean;
  sortOrder: number;
  modifierGroups: ModifierGroup[];
};

export type SaveCategoryPayload = {
  name: string;
  description?: string;
  branch_id?: string;
  sort_order?: number;
  is_active?: boolean;
};

export type ModifierInput = {
  name: string;
  price: number;
};

export type ModifierGroupInput = {
  name: string;
  is_required: boolean;
  min_select: number;
  max_select: number;
  modifiers: ModifierInput[];
};

export type SaveFoodItemPayload = {
  category_id: string;
  branch_id?: string;
  name: string;
  description?: string;
  base_price: number;
  tax_rate?: number;
  tax_rate_id?: string | null;
  kitchen_station_id?: string | null;
  is_available?: boolean;
  modifier_groups?: ModifierGroupInput[];
};
