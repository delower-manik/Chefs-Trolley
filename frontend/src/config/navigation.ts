// Navigation is generated from the Admin Core Modules list in
// 01_CHEFS_TROLLEY_FULL_SYSTEM_ARCHITECTURE.md §5. Each item declares the
// build phase it belongs to (see the Master Build Prompt's Execution Order).
// `implemented` items link to a real route with a real API contract.
// Not-yet-implemented items render disabled with a phase badge instead of a
// fake "Coming Soon" screen -- per Non-Negotiable Rule #4, we never ship a
// placeholder route pretending to be a working screen.

export type NavItem = {
  id: string;
  label: string;
  icon: string; // Material Symbols Outlined glyph name
  href: string;
  phase: number;
  implemented: boolean;
  permission?: string; // RBAC permission key required to see this item
  feature?: "gift_cards" | "campaigns" | "reservations" | "wifi"; // hidden when the business switches the module off
};

export type NavGroup = {
  group: string;
  items: NavItem[];
};

export const NAVIGATION: NavGroup[] = [
  {
    group: "Overview",
    items: [
      { id: "dashboard", label: "Dashboard", icon: "dashboard", href: "/dashboard", phase: 1, implemented: true },
      { id: "pos", label: "POS", icon: "point_of_sale", href: "/pos", phase: 3, implemented: true, permission: "pos.access" },
      { id: "kitchen", label: "Kitchen / KDS", icon: "soup_kitchen", href: "/kitchen", phase: 3, implemented: true, permission: "kds.access" },
      { id: "orders", label: "Orders", icon: "receipt_long", href: "/orders", phase: 3, implemented: true, permission: "orders.view" },
    ],
  },
  {
    group: "Restaurant Operations",
    items: [
      { id: "floor", label: "Floor Plans", icon: "grid_view", href: "/floor-plans", phase: 2, implemented: true, permission: "tables.manage" },
      { id: "tax", label: "Tax Rates", icon: "percent", href: "/tax-rates", phase: 2, implemented: true, permission: "tax.manage" },
      { id: "tables", label: "Tables", icon: "table_restaurant", href: "/tables", phase: 2, implemented: true, permission: "tables.manage" },
      { id: "reservations", label: "Table Reservations", icon: "event_seat", href: "/reservations", phase: 2, implemented: true, permission: "reservations.manage", feature: "reservations" },
      { id: "waiters", label: "Waiter Orders", icon: "person", href: "/waiter-orders", phase: 3, implemented: true, permission: "orders.create" },
      { id: "kitchens", label: "Kitchen Stations", icon: "countertops", href: "/kitchen-stations", phase: 2, implemented: true, permission: "kitchen.manage" },
      { id: "printers", label: "Printers / ESC-POS", icon: "print", href: "/printers", phase: 3, implemented: true, permission: "printers.manage" },
      { id: "cash", label: "Cash Register", icon: "point_of_sale", href: "/cash-register", phase: 3, implemented: true, permission: "cash.manage" },
    ],
  },
  {
    group: "Menu",
    items: [
      { id: "categories", label: "Categories", icon: "category", href: "/menu/categories", phase: 2, implemented: true, permission: "menu.manage" },
      { id: "items", label: "Food Items", icon: "restaurant_menu", href: "/menu/items", phase: 2, implemented: true, permission: "menu.manage" },
      { id: "modifiers", label: "Modifiers", icon: "tune", href: "/menu/modifiers", phase: 2, implemented: false, permission: "menu.manage" },
      { id: "recipes", label: "Recipes", icon: "menu_book", href: "/menu/recipes", phase: 4, implemented: true, permission: "menu.manage" },
      { id: "combos", label: "Combos & Deals", icon: "loyalty", href: "/menu/combos", phase: 2, implemented: true, permission: "menu.manage" },
    ],
  },
  {
    group: "Inventory",
    items: [
      { id: "ingredients", label: "Ingredients", icon: "kitchen", href: "/inventory/ingredients", phase: 4, implemented: true, permission: "inventory.manage" },
      { id: "warehouses", label: "Warehouses", icon: "warehouse", href: "/inventory/warehouses", phase: 4, implemented: true, permission: "inventory.manage" },
      { id: "purchase", label: "Purchases", icon: "add_shopping_cart", href: "/inventory/purchases", phase: 4, implemented: true, permission: "inventory.manage" },
      { id: "suppliers", label: "Suppliers", icon: "local_shipping", href: "/inventory/suppliers", phase: 4, implemented: true, permission: "inventory.manage" },
      { id: "adjust", label: "Stock Adjustments", icon: "swap_vert", href: "/inventory/adjustments", phase: 4, implemented: true, permission: "inventory.manage" },
      { id: "transfers", label: "Stock Transfers", icon: "sync_alt", href: "/inventory/transfers", phase: 4, implemented: true, permission: "inventory.manage" },
    ],
  },
  {
    group: "Delivery",
    items: [
      { id: "delivery", label: "Delivery Management", icon: "two_wheeler", href: "/delivery", phase: 6, implemented: true, permission: "delivery.manage" },
      { id: "drivers", label: "Drivers / Riders", icon: "moped", href: "/delivery/riders", phase: 6, implemented: true, permission: "delivery.manage" },
      { id: "delivery_zones", label: "Delivery Zones", icon: "map", href: "/delivery/zones", phase: 6, implemented: true, permission: "delivery.manage" },
    ],
  },
  {
    group: "Customers & Growth",
    items: [
      { id: "customers", label: "Customers", icon: "groups", href: "/customers", phase: 5, implemented: true, permission: "customers.view" },
      { id: "promotions", label: "Promotions", icon: "local_offer", href: "/promotions", phase: 5, implemented: true, permission: "promotions.manage" },
      { id: "reviews", label: "Reviews", icon: "star", href: "/reviews", phase: 5, implemented: true, permission: "reviews.view" },
      { id: "gift-cards", label: "Gift Cards", icon: "card_giftcard", href: "/gift-cards", phase: 5, implemented: true, permission: "giftcards.view", feature: "gift_cards" },
      { id: "segments", label: "Segments", icon: "filter_alt", href: "/segments", phase: 5, implemented: true, permission: "campaigns.manage", feature: "campaigns" },
      { id: "campaigns", label: "Campaigns", icon: "campaign", href: "/campaigns", phase: 5, implemented: true, permission: "campaigns.manage", feature: "campaigns" },
      { id: "notifications", label: "Notifications", icon: "notifications_active", href: "/notifications", phase: 5, implemented: true, permission: "notifications.manage" },
    ],
  },
  {
    group: "People & Finance",
    items: [
      { id: "employees", label: "Employees", icon: "badge", href: "/hr/employees", phase: 7, implemented: true, permission: "hr.manage" },
      { id: "attendance", label: "Attendance", icon: "schedule", href: "/hr/attendance", phase: 7, implemented: true, permission: "hr.manage" },
      { id: "payroll", label: "Payroll", icon: "payments", href: "/hr/payroll", phase: 7, implemented: true, permission: "payroll.manage" },
      { id: "accounting", label: "Accounting", icon: "account_balance", href: "/finance/accounting", phase: 7, implemented: true, permission: "finance.manage" },
    ],
  },
  {
    group: "Digital Presence",
    items: [
      { id: "cms", label: "Website CMS", icon: "language", href: "/cms", phase: 8, implemented: true, permission: "cms.manage" },
      { id: "seo", label: "SEO", icon: "search", href: "/cms/seo", phase: 8, implemented: false, permission: "cms.manage" },
      { id: "media", label: "Media Library", icon: "perm_media", href: "/cms/media", phase: 8, implemented: true, permission: "cms.manage" },
      { id: "theme", label: "Theme", icon: "palette", href: "/cms/theme", phase: 8, implemented: true, permission: "cms.manage" },
    ],
  },
  {
    group: "Integrations",
    items: [
      { id: "payments", label: "Payment Gateways", icon: "payments", href: "/integrations/payments", phase: 8, implemented: true, permission: "integrations.manage" },
      { id: "wifi", label: "Free Wi-Fi Zone", icon: "wifi", href: "/integrations/wifi", phase: 8, implemented: true, permission: "integrations.manage", feature: "wifi" },
      { id: "branches", label: "Branches / Outlets", icon: "store", href: "/branches", phase: 10, implemented: true, permission: "branches.manage" },
      { id: "webhooks", label: "Webhooks", icon: "webhook", href: "/integrations/webhooks", phase: 8, implemented: true, permission: "integrations.manage" },
    ],
  },
  {
    group: "Reports & Control",
    items: [
      { id: "reports", label: "Reports", icon: "assessment", href: "/reports", phase: 9, implemented: true, permission: "reports.view" },
      { id: "users", label: "Users & Roles", icon: "manage_accounts", href: "/users", phase: 10, implemented: true, permission: "users.manage" },
      { id: "activity", label: "Activity Logs", icon: "history", href: "/activity-logs", phase: 10, implemented: true, permission: "audit.view" },
      { id: "backups", label: "Backups", icon: "backup", href: "/backups", phase: 16, implemented: true, permission: "backups.manage" },
      { id: "security", label: "My Security", icon: "shield_lock", href: "/account/security", phase: 16, implemented: true },
      { id: "settings", label: "Settings", icon: "settings", href: "/settings", phase: 17, implemented: true, permission: "settings.manage" },
    ],
  },
];

export const ALL_NAV_ITEMS: NavItem[] = NAVIGATION.flatMap((g) => g.items);
