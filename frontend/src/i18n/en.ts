// English is the source of truth. Every key here should exist in bn.ts; a missing Bangla key falls back to English.
export const en = {
  "lang.name": "English",
  "common.save": "Save", "common.cancel": "Cancel", "common.delete": "Delete", "common.edit": "Edit", "common.close": "Close",
  "common.loading": "Loading…", "common.search": "Search", "common.back": "Back", "common.signOut": "Sign out", "common.yes": "Yes", "common.no": "No",
  "common.saved": "Saved.", "common.couldntSave": "Couldn't save. Try again.", "common.on": "On", "common.off": "Off",
  "header.live": "Live", "header.connecting": "Connecting", "header.offline": "Offline", "header.menu": "Menu", "header.theme": "Theme", "header.language": "Language",
  "header.searchPlaceholder": "Search orders, items, customers...", "header.notifications": "Notifications", "header.mySecurity": "My security",
  "theme.system": "System", "theme.light": "Light", "theme.dark": "Dark",

  "nav.group.Overview": "Overview", "nav.group.Restaurant Operations": "Restaurant Operations", "nav.group.Menu": "Menu", "nav.group.Inventory": "Inventory",
  "nav.group.Delivery": "Delivery", "nav.group.Customers & Growth": "Customers & Growth", "nav.group.People & Finance": "People & Finance",
  "nav.group.Digital Presence": "Digital Presence", "nav.group.Integrations": "Integrations", "nav.group.Reports & Control": "Reports & Control",
  "nav.dashboard": "Dashboard", "nav.pos": "POS", "nav.kitchen": "Kitchen / KDS", "nav.orders": "Orders", "nav.floor": "Floor Plans", "nav.tax": "Tax Rates",
  "nav.tables": "Tables", "nav.reservations": "Table Reservations", "nav.waiters": "Waiter Orders", "nav.kitchens": "Kitchen Stations", "nav.printers": "Printers / ESC-POS",
  "nav.cash": "Cash Register", "nav.categories": "Categories", "nav.items": "Food Items", "nav.modifiers": "Modifiers", "nav.recipes": "Recipes", "nav.combos": "Combos & Deals",
  "nav.ingredients": "Ingredients", "nav.warehouses": "Warehouses", "nav.purchase": "Purchases", "nav.suppliers": "Suppliers", "nav.adjust": "Stock Adjustments",
  "nav.transfers": "Stock Transfers", "nav.delivery": "Delivery Management", "nav.drivers": "Drivers / Riders", "nav.delivery_zones": "Delivery Zones",
  "nav.customers": "Customers", "nav.promotions": "Promotions", "nav.reviews": "Reviews", "nav.gift-cards": "Gift Cards", "nav.segments": "Segments", "nav.campaigns": "Campaigns",
  "nav.notifications": "Notifications", "nav.employees": "Employees", "nav.attendance": "Attendance", "nav.payroll": "Payroll", "nav.accounting": "Accounting",
  "nav.cms": "Website CMS", "nav.seo": "SEO", "nav.media": "Media Library", "nav.theme": "Theme", "nav.payments": "Payment Gateways", "nav.wifi": "Free Wi-Fi Zone",
  "nav.branches": "Branches / Outlets", "nav.webhooks": "Webhooks", "nav.reports": "Reports", "nav.users": "Users & Roles", "nav.activity": "Activity Logs",
  "nav.backups": "Backups", "nav.security": "My Security", "nav.settings": "Settings",

  "login.subtitle": "Sign in to the restaurant management console", "login.email": "Email", "login.password": "Password", "login.signIn": "Sign in", "login.signingIn": "Signing in...",
  "login.code": "Authenticator code or recovery code", "login.verify": "Verify",
  "login.codeHelp": "Open your authenticator app and enter the 6-digit code. Lost your phone? Use one of your recovery codes.",

  "dashboard.todaySales": "Today's Sales", "dashboard.orders": "Orders Today", "dashboard.kitchen": "Open Kitchen Orders", "dashboard.stock": "Stock Alerts",

  "settings.title": "Settings", "settings.subtitle": "Business details, language, receipts and which modules are switched on.",
  "settings.business": "Business", "settings.name": "Business name", "settings.legalName": "Legal name", "settings.address": "Address", "settings.phone": "Phone",
  "settings.email": "Email", "settings.taxId": "BIN / VAT registration number",
  "settings.regional": "Language, time & currency", "settings.defaultLanguage": "Default language", "settings.timezone": "Time zone",
  "settings.currencyCode": "Currency code", "settings.currencySymbol": "Currency symbol", "settings.decimals": "Decimals shown on screen",
  "settings.currencyNote": "Display only — prices are always stored and calculated in BDT. Receipts always show 2 decimals.",
  "settings.receipts": "Receipts", "settings.receiptLanguage": "Receipt language", "settings.receiptHeader": "Header line", "settings.receiptFooter": "Footer text",
  "settings.showTaxId": "Print the BIN / VAT number on receipts",
  "settings.thermalNote": "Thermal (ESC/POS) printers cannot print Bangla letters — they come out as “?”. Bangla receipts work for browser/PDF printing.",
  "settings.website": "Online ordering (website)", "settings.web.ordering": "Website ordering", "settings.web.cod": "Cash on delivery / at pickup", "settings.web.pickup": "Pickup orders", "settings.web.min_order": "Minimum order amount", "settings.websiteNote": "Customers can order from the public website only while \"Website ordering\" is on. It is off until you switch it on. Prices, tax and discounts are always calculated by the server.",
  "settings.features": "Modules", "settings.featuresNote": "Switching a module off hides it from the menu and the server refuses its requests. Existing data is kept.",
  "settings.feature.gift_cards": "Gift cards", "settings.feature.campaigns": "Segments & campaigns", "settings.feature.reservations": "Table reservations", "settings.feature.wifi": "Free Wi-Fi zone",
  "settings.appearance": "Appearance (this device)",

  "security.title": "My Security", "security.profile": "Profile", "security.changePassword": "Change password", "security.twoStep": "Two-step sign-in",
  "security.sessions": "Where you're signed in", "backups.title": "Backups", "backups.now": "Back up now",
} as const;

export type MessageKey = keyof typeof en;
