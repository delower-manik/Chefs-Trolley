> **Final package.** Start with `START_HERE.md`, then `docs/01…10` (profile, requirements, server, install, API keys, structure, data schema, blueprint, user manual, security/testing/readiness).

# Chefs Trolley — Phase 1 through 22 (15b = Gateways; 16 = Security/MFA/Backups; 17 = Settings/Bangla/Light mode/OpenAPI; 18 = Docker, CI, health; 19 = Customer website; 20 = Rider app; 21 = Staff app; 22 = Customer app)

Production-grade foundation for the Chefs Trolley Restaurant OS: unified
design system, authenticated admin shell, RBAC, Orders, Menu, Tables/
Reservations, POS + Kitchen Display (KDS), Inventory (with warehouses and
a full purchase-order workflow), Customers & Growth (loyalty, promo codes,
reviews, notifications), Delivery (riders, zones, a dispatch board),
People & Finance (attendance-driven payroll, a revenue-vs-expenses view),
Digital Presence & Integrations — a structured (never raw-HTML)
website CMS, a real media library, site branding settings, and
configuration management for payment gateways, Wi-Fi zones/vouchers, and
webhooks — Reports & Analytics (sales, product, category, delivery,
customer and branch-comparison reports computed live from the operational
data, with a CSV export for sales) — and now Administration: Users & Roles,
Branches and a read-only Activity Log, with the privilege-escalation
guards that kind of screen needs.

**Phase 11 (stabilization)** is the first time the backend was actually
booted: `composer install`, all migrations and seeders, the full PHPUnit
suite (200 tests) and a real HTTP smoke test (CORS, login, token types) were
run, and the real bugs that surfaced are fixed — see "Phase 11 additions".

**Phase 12 (Restaurant Core)** adds exact-money pricing, tax configuration,
kitchen stations, a drag-and-drop floor plan editor, combos/deals and waiter
table tabs — see "Phase 12 additions".

**Phase 13 (POS completion)** adds split/partial payments with tips,
permissioned discounts, refunds/voids (with stock reversal), cash-register
shifts with a Z-report, and receipt/kitchen-ticket printing (ESC/POS) — see
"Phase 13 additions".

**Phase 14 (Real-time & Notifications)** adds live updates (Pusher / Reverb / Ably),
a cron-driven queue for shared hosting, real SMS / email / Firebase-push delivery with a
delivery log, and signed, retried webhook delivery — see "Phase 14 additions".

**Phase 15 (Marketing)** adds gift cards (a ledger-backed payment method), rule-based customer
segments, scheduled/sent campaigns over the Phase 14 pipeline, and real marketing consent (opt-out
flag + signed unsubscribe link) — see "Phase 15 additions". **Phase 15b** adds the seven payment
gateways — see "Phase 15b additions". SEO is **not** built yet.

This covers **Phase 1 through 15** of the build per
`01_CHEFS_TROLLEY_FULL_SYSTEM_ARCHITECTURE.md §17`. It is not the complete
platform — see "What's not built yet" below.

---

## What's actually working

**Frontend** (`/frontend` — Next.js 14 App Router, TypeScript, Tailwind)
- Login → JWT session → protected admin shell (edge middleware + client guard)
- RBAC: permissions resolved server-side, `<Can>` component gates UI (never the
  only line of defense — the API re-checks everything)
- Full navigation config mirroring every module in the architecture doc; only
  Dashboard, Orders, Menu (Categories/Food Items/Recipes), Tables,
  Reservations, POS, Kitchen, and Inventory (Ingredients/Suppliers/
  Purchases/Adjustments) and, since Phase 10, Users & Roles, Branches and
  Activity Logs are wired to real routes — everything else (Settings,
  Printers, and the other not-yet-built modules) shows a disabled state with
  its planned phase number instead of a fake screen
- Orders module: list (search/filter/pagination), detail view, audited cancel
  action — loading/empty/error states throughout
- Menu module: categories CRUD, food items CRUD with base price/tax and a
  full modifier-group editor (groups + their modifiers, required/min/max),
  and Recipes (pick a food item, define what ingredients/quantities it
  consumes)
- Tables: branch-scoped table grid with live status changes
- Reservations: list + create + cancel, with a state-machine guard (only
  pending/confirmed reservations can be cancelled) and a double-booking
  conflict check on the same table/time window
- POS (`/pos`): category-filtered item grid, a modifier picker for items with
  option groups, a running cart, and checkout that posts a real order —
  prices are never trusted from the client, only recomputed server-side.
  Choosing "Delivery" surfaces a zone picker (sets the delivery fee
  server-side) and an address field
- Kitchen Display (`/kitchen`): a 4-column live board (New/Accepted/
  Preparing/Ready) with bump-forward and one-step-back "undo", polling every
  5s (an honest interim for the WebSocket layer the architecture calls for
  in a later phase — see gaps below)
- Inventory: Ingredients (stock levels aggregated across warehouses,
  low-stock filter, reorder level), Warehouses (multiple storage locations
  per branch, one marked default, guaranteed to exist automatically),
  Suppliers, Purchases (draft PO → receive → stock lands, or cancel before
  receiving), Stock Transfers (move stock between warehouses, blocked if
  the source doesn't actually have enough), Stock Adjustments (manual +/-
  correction or reasoned waste, plus a full ledger feed of every movement —
  purchases, sales, adjustments, waste, and transfers — filterable by
  warehouse)
- Customers & Growth: Customers (search, order history, loyalty balance),
  a Loyalty ledger (points automatically earned on checkout, plus a
  reasoned manual adjustment tool), Promotions/Coupons (create a code,
  pause/resume it, watch its usage count climb as customers redeem it),
  Reviews (start unpublished, moderated with an explicit publish/unpublish
  action), and an internal Notifications log with a clearly-labeled warning
  that it doesn't actually send anything yet (see gaps below)
- Delivery (`/delivery`): a dispatch board of every delivery order, newest
  first, filterable by status; assign a rider, mark picked up, mark
  delivered (with an optional recipient name and proof note), or mark a
  failed attempt — each a real state transition, not just a label change.
  Riders (`/delivery/riders`) get a quick status toggle (available/busy/
  offline) alongside full CRUD. Delivery Zones (`/delivery/zones`) are
  named areas with a flat fee that POS picks up automatically
- People & Finance: Employees (`/hr/employees`, a staff roster separate
  from system login accounts), Attendance (`/hr/attendance`, a daily
  roster showing every active employee — including ones nobody has marked
  yet, rather than silently assuming absent), Payroll (`/hr/payroll`,
  generate → review → finalize → pay, with attendance-based deductions
  computed from real records and a manual bonus editable while still a
  draft), and Accounting (`/finance/accounting`, a revenue-vs-expenses
  summary for a date range plus expense tracking — explicitly not a full
  bookkeeping system, see gaps below)
- Digital Presence (`/cms`): a page editor built from typed, structured
  sections (hero/text/image/menu-highlight/gallery/CTA) — never a raw-HTML
  field a manager could inject arbitrary markup or scripts through — with
  an explicit draft/publish split so a half-finished edit can't accidentally
  go live. Media Library (`/cms/media`) does a real file upload to local
  disk storage, validated by actual file content (not just the extension),
  size-capped, and stored under a generated filename. Theme (`/cms/theme`)
  covers site branding, logo, and contact/social details
- Integrations: Payment Gateways (`/integrations/payments`) — a fixed,
  seeded catalog (bKash/Nagad/SSLCommerz/Cash on Delivery) that can be
  enabled and configured, with credentials encrypted at rest and never
  echoed back in any API response; explicitly labeled as configuration
  only, not a working payment flow (see gaps). Free Wi-Fi Zones
  (`/integrations/wifi`) manage zones and generate voucher codes — also
  explicitly labeled as bookkeeping, not a live router connection.
  Webhooks (`/integrations/webhooks`) let you register a URL and event to
  subscribe to, with a signing secret shown exactly once at creation —
  also explicitly labeled as configuration only, since nothing dispatches
  to them yet
- Typed API client now also handles `FormData` uploads (skips JSON
  stringification and lets the browser set its own multipart
  `Content-Type`) alongside its existing token refresh, idempotency keys,
  and branch-scope headers
- Reports (`/reports`): one page, six tabs — Sales (revenue, orders,
  average order value, discounts/tax/delivery fees, by order source, by
  day, and an **Export CSV** button), Products (top sellers by revenue),
  Categories, Delivery (delivered/failed/in-progress, success rate, average
  assignment-to-delivery time, per-rider), Customers (new customers, top
  spenders, loyalty points earned/redeemed) and Branch comparison. A date
  range picker defaults to the current month in Dhaka time; an invalid or
  oversized range is flagged inline and no request is sent. Only the
  active tab fetches its report. Proportional bars sit next to the real
  numbers as a visual cue — there is no charting library (see gaps)
- Users & Roles (`/users`, two tabs). **Users**: searchable, status-filterable,
  paginated list; create / edit with role and branch pickers that only offer
  what *you* are allowed to hand out; reset password; deactivate / activate;
  delete. On your own row the destructive actions are disabled and the form
  only edits your name and email. **Roles & permissions**: every role with
  its permission and user counts; a permission checklist grouped by module
  where permissions you don't hold are disabled; the built-in Super Admin
  role is view-only; a role that still has users can't be deleted. API
  messages and per-field validation errors are shown inline
- Branches (`/branches`): list, create and edit (name, code, address, phone,
  timezone with a searchable list of IANA zones)
- Activity Logs (`/activity-logs`): filter by person/action text, category,
  exact action and date range (dates are calendar days in the branch's
  timezone), paginated, with the recorded changes expandable per entry and
  rendered as plain text. There is no edit or delete anywhere — see Phase 10
- `npm run build` and `npx tsc --noEmit` both pass with zero errors (41 routes)
- `npm test` (Vitest): 45 unit tests (Phase 14 added 5); before that 40 unit tests (Phase 12 added 7, Phase 13 added 6); originally 27 covering formatting (including the
  Dhaka-date helpers), the navigation config's own invariants, the API
  client's error handling (JSON, FormData upload, and blob download), and
  the administration helpers (permission grouping, list toggling, query
  building, field-error extraction)

**Backend** (`/backend` — Laravel 12 API, hand-authored)
- Migrations: branches, users (+branch pivot), RBAC (roles/permissions),
  orders/order_items, audit_logs, categories/food_items/modifier_groups/
  modifiers, dining_tables/reservations, units/suppliers/warehouses/
  ingredients/ingredient_stocks/stock_ledger_entries/purchases/
  purchase_items/stock_transfers/stock_transfer_items, recipe_ingredients,
  customers/loyalty_ledger_entries, promotions/promotion_redemptions,
  reviews, notifications — UUID primary keys throughout
- `AuthController` (login/refresh/me/logout via Sanctum tokens)
- `OrderController` — `index`/`show`/`cancel` plus `store`: the POS order
  engine. Every price is recomputed from the current `FoodItem`/`Modifier`
  records server-side (a client can request a food item + quantity, never
  a price); modifiers are validated to actually belong to the item ordered;
  `Idempotency-Key` is checked against existing orders so a retried request
  can't double-charge; a unique-constraint collision on the generated order
  code retries up to twice; consumes recipe ingredients (aggregated across
  the whole order, debited as one ledger entry per ingredient from the
  branch's default warehouse) unless the order is only held as a draft, and
  is a race-safe hard stop on insufficient stock (see `StockLedgerEntry`
  below) — **and now also**: validates and prices a `promo_code`
  server-side (active/date-window/minimum-order/usage-limit/per-customer-
  limit all checked, with the global usage cap enforced by a conditional
  atomic `UPDATE ... WHERE used_count < limit` inside the transaction so
  two checkouts racing for the last use of a limited code can't both win),
  and **earns the attached customer loyalty points** (1 point per 100 BDT
  of the final total, skipped entirely for a held draft)
- `KitchenTicketController` — a forward-only kitchen state machine
  (new → accepted → preparing → ready → served → completed) plus one
  step of "reopen" to undo an accidental bump
- `CategoryController`, `FoodItemController` (with nested modifier-group
  sync in a DB transaction), `TableController`, `ReservationController`
  (with the cancellable-state guard and the double-booking conflict check)
- `WarehouseController` (CRUD, only one default per branch, can't delete a
  warehouse that still holds stock or is the default), `IngredientController`
  (stock shown is a live sum across warehouses, never a stale cached column),
  `SupplierController`, `PurchaseController` (draft → `receive` lands stock,
  or `cancel` before receiving — a purchase that already landed stock can't
  be cancelled through this endpoint, see gaps), `StockTransferController`
  (moves stock between two warehouses in the same branch; rejects the whole
  transfer upfront if the source doesn't have enough of any line, rather
  than partially moving stock), `StockAdjustmentController` (adjustment/
  waste, waste always requires a reason and can only ever reduce stock),
  `RecipeController` (full-replace sync, same pattern as modifier groups)
  — `StockLedgerEntry::record()` is the single write path for every stock
  movement anywhere in the system: it locks the specific ingredient+
  warehouse balance row, so two movements landing at the same instant (a
  purchase arriving while a sale consumes stock, or two checkouts racing
  for the last portion of an ingredient) can't desync the balance from the
  ledger — and when called with `allowNegative: false` (as POS checkout
  does), the zero-floor check happens *inside* that same lock, which is
  what makes the checkout block genuinely race-safe rather than a
  check-then-act gap a second concurrent request could slip through
- `CustomerController` (CRUD, order history, and the loyalty-adjustment +
  ledger endpoints), `LoyaltyLedgerEntry::record()` (the same row-locked
  ledger discipline as stock, minus the per-warehouse dimension — a
  customer only has the one balance, so it can never drift from the ledger
  even under concurrent orders), `PromotionController` (CRUD; `Promotion::
  calculateDiscount()` handles both percentage and fixed types and always
  caps the result at the subtotal itself, so a coupon can never make a
  total negative), `ReviewController` (create + `publish`/`unpublish` as
  distinct, audited moderation actions), `NotificationController` (create
  a draft, `send` marks it sent exactly once — see the "not a delivery
  system" note below)
- `DeliveryZoneController` and `RiderController` (CRUD; a rider can't be
  deleted while they have an active delivery, a zone can't be deleted once
  it has delivery history), `DeliveryController` — the dispatch board's
  `assign`/`pickup`/`deliver`/`fail` actions. Deliberately does **not**
  duplicate the general order lifecycle: `orders.status` (already able to
  express `assigned`/`picked_up`/`delivered` since Phase 1) stays the
  source of truth for where an order is, while `delivery_assignments` only
  tracks what's genuinely delivery-specific — which rider, which zone,
  each handoff timestamp, and a `failed` outcome the general Order
  lifecycle has no equivalent for. A rider is automatically freed back to
  "available" once they have no other open delivery, whether that
  delivery succeeded, failed, or they were swapped off it mid-flight.
  `OrderController@store` also now treats a `delivery_zone_id` as
  authoritative over any `delivery_fee` the client sent directly — same
  "never trust a client-supplied money value when a server-side source of
  truth exists" principle as recipe pricing and promo discounts
- `EmployeeController` (CRUD; a staff roster deliberately separate from
  `users` — not every employee needs a login), `AttendanceController` (a
  daily roster upserted on `(employee_id, date)` so re-marking a day
  corrects it instead of duplicating a row), `PayrollController` — the
  most cross-domain controller in this pass: `generate` computes one
  entry per active employee with absent-day deductions pulled from real
  `AttendanceRecord` rows in the period window (not estimated), safely
  re-runnable while still a draft (replaces entries, never stacks
  duplicates); `finalize` locks them; `pay` sums the entries and records
  one aggregate `Expense` under an auto-created "Salaries" category, so
  payroll actually shows up in Accounting instead of living in a silo.
  `ExpenseController`/`ExpenseCategoryController` (a system-generated
  expense, like the one payroll creates, can't be edited or deleted
  through these endpoints — same protective instinct as ingredients'
  `current_stock` never being directly settable). `AccountingController`
  — a `summary` endpoint computing revenue (from real, non-cancelled
  Orders) minus expenses for a date range; deliberately not a general
  ledger, see gaps below
- `CmsPageController` (publish/unpublish are separate, explicit, audited
  actions from a plain edit — a half-finished draft can never accidentally
  go live via a generic `PUT`; sections are validated against a fixed list
  of known typed block types, not accepted as arbitrary/raw HTML),
  `MediaController` (`store` validates the uploaded file by its actual
  MIME type via Laravel's `mimes` rule — not the filename extension a
  client sent — size-caps it, and stores it under a server-generated UUID
  filename so the client's original filename is never used as a storage
  path), `SiteSettingController` (`SiteSetting::current()` is a singleton
  pattern: it always reads/writes the one earliest row rather than letting
  a second settings row ever be created)
- `PaymentGatewayController` (a fixed, seeded catalog — `PaymentGatewaysSeeder`
  — rather than letting the API create arbitrary new gateway keys; `config`
  is stored via Laravel's `encrypted:array` cast, and credential values are
  never included in any API response, including right after they're saved),
  `WebhookController` (the signing secret is generated server-side and
  returned exactly once, in the creation response only — every subsequent
  `index`/`update` response omits it, the same "shown once" pattern a real
  API key uses), `WifiZoneController` + `WifiVoucherController` (branch-scoped
  zones, batch voucher generation with unique codes, and a `redeem` action
  that can only ever mark a voucher used once)
- `ReportController` (Phase 9) — seven read-only `GET /reports/*`
  endpoints behind `reports.view`: `sales`, `sales/export` (streamed CSV),
  `products`, `categories`, `delivery`, `customers`, `branches`. All are
  computed live from the operational tables; "revenue" uses the same rule
  as Accounting and the Dashboard (orders that aren't draft/cancelled/
  refunded, summed on `grand_total`). `from`/`to` are validated, inclusive
  calendar dates in the active branch's timezone and are capped at 366
  days. The branch comparison is deliberately not limited to the active
  branch, but is still authorized: a Super Admin sees every branch,
  anyone else only the branches they are assigned to
- Administration (Phase 10) — `UserController`, `RoleController`,
  `BranchController` and `ActivityLogController`, plus `App\Support\AdminAccess`
  (the single home of the privilege-escalation rules) and the
  `EnsureActiveSession` middleware. Details in "Phase 10 additions"
- `DashboardController` — real SQL aggregates; `openKitchenOrders` and
  `stockAlerts` are both wired to real queries (previously stubbed `0`)
- `EnsurePermission` + `EnsureBranchScope` middleware — authorization is
  enforced server-side independent of what the frontend hides
- `RolesAndPermissionsSeeder` + `UnitsSeeder` + `PaymentGatewaysSeeder`,
  chained from `DatabaseSeeder` — the full Phase 1–8 permission catalog (no
  new permission was needed for Digital Presence or Integrations —
  `cms.manage` and `integrations.manage` were both already seeded back in
  Phase 1 as placeholders for exactly this) and a starter unit list (kg, g,
  L, ml, pc, dz, pkt)
- Every `.php` file passes `php -l` (217/217, tests included)

---

## Setup

### Frontend
```bash
cd frontend
cp .env.example .env.local   # point NEXT_PUBLIC_API_BASE_URL at your API
npm install
npm run dev
```

### Backend
Requires PHP 8.3+, Composer 2 and the `pdo_sqlite` (dev/tests) or
`pdo_mysql`/`pdo_pgsql` extension.
```bash
cd backend
composer install
cp .env.example .env            # set DB_*, APP_URL, CORS_ALLOWED_ORIGINS
php artisan key:generate
php artisan migrate --seed      # units, roles/permissions, payment-gateway catalog
php artisan chefs:create-admin you@example.com   # prompts for a password (or --generate)
php artisan storage:link        # uploaded media must be publicly servable
php artisan serve
```
`chefs:create-admin` creates the first Super Admin (and a first branch if none
exists); there is no default account. Point the frontend's
`NEXT_PUBLIC_API_BASE_URL` at `http://localhost:8000/api/v1` and add the
frontend's origin to `CORS_ALLOWED_ORIGINS`.

**Dependency note:** no `composer.lock` is shipped. The sandbox this was built
in could not reach Packagist, so dependencies were resolved from their GitHub
repositories there; on your machine a normal `composer install` from
Packagist is expected to work, but that exact path was **not** exercised here.
Commit the `composer.lock` it generates.

---

## Running the backend test suite
```bash
cd backend
composer install
composer test        # or: vendor/bin/phpunit
```
Last run (Phase 18): **445 tests, all passing** on PHP 8.3 /
SQLite.
Tests run against an in-memory SQLite database (`phpunit.xml`), so no
migration/seeding step is needed first — `RefreshDatabase` handles it per
test. Requires the `pdo_sqlite` PHP extension. The suite has only been run
on SQLite; MySQL/PostgreSQL behaviour (e.g. date casts, aggregate typing) is
unverified — run it against your production engine in CI.

Coverage in this pass: auth (login success/failure/inactive user, `/me`
permission resolution), Orders (branch-scoped listing, permission
enforcement, cancellable-state guard, audit trail, and the full POS order
engine: server-side price recomputation, modifier ownership validation,
unavailable-item rejection, hold/draft, idempotency-key replay), Kitchen
(the forward/reopen state machine and the board's status filter), Menu
(category create/delete-guard, food item + modifier-group create/replace),
Reservations (the double-booking conflict check, plus the cancellable-state
guard), Inventory (a new branch getting a default warehouse automatically,
a purchase only landing stock once received rather than at creation,
cancelling a draft purchase touching nothing, waste requiring a reason and
only ever reducing stock, manual adjustments moving either direction, the
low-stock filter, transfers moving stock between warehouses and being
rejected outright when the source doesn't have enough, and — the key
cross-domain tests — placing an order correctly debiting every ingredient
in that dish's recipe aggregated across multiple line items sharing one
ingredient, a held draft order debiting nothing, and an order that would
oversell an ingredient being rejected with nothing created at all), and
Customers & Growth (a customer can't share a phone number with another,
order history shows up correctly, a percentage promo discounts and caps
correctly, a fixed promo respects its minimum-order floor, an expired or
paused or usage-exhausted code is rejected with a specific reason, a
per-customer limit requires a customer to be attached and is enforced
across repeat orders, placing an order earns the right number of loyalty
points while a held order earns none, a manual loyalty adjustment requires
a reason, a new review starts unpublished and only appears in a
published-only listing after an explicit publish action, and a
notification can only be marked sent once), and Delivery (a delivery order
gets a pending assignment automatically, the full assign → pickup →
deliver flow including the rider being marked busy then freed again,
pickup being rejected before a rider is assigned, a failed delivery
freeing the rider and being retryable with a second rider, reassigning a
rider freeing the previous one, reassignment being rejected once a
delivery has been picked up, an inactive rider or a non-delivery order
being rejected for assignment, a delivery zone's fee overriding whatever
the client tried to send directly, a rider's status toggling via a
partial update the same way the Table/Ingredient bug fix required, a
rider with an active delivery being undeletable, and two zones in the
same branch not being able to share a name), and People & Finance
(two employees can't share a phone number, editing an employee via a
partial update works, an employee with payroll history can't be deleted,
marking attendance twice for the same day corrects the record instead of
duplicating it, the daily roster shows every active employee even before
anyone marks them, payroll deductions are computed from real attendance
records within the exact period window — and an absence outside that
window correctly doesn't count, regenerating a draft payroll period
replaces entries rather than duplicating them, a bonus added while draft
recomputes net pay, the full draft → finalized → paid lifecycle including
each state-guard rejection along the way, paying payroll creates a real
Expense record under an auto-created Salaries category, a system-
generated expense can't be edited directly, the accounting summary
correctly excludes cancelled orders from revenue, and two expense
categories in the same branch can't share a name), Digital Presence (a
page starts unpublished and moves through explicit publish/unpublish
actions, two pages can't share a slug, an unknown section type is
rejected rather than silently accepted as raw content, a page can be
edited with a partial update, uploaded media is stored under a generated
filename rather than the client's own, a non-image file is rejected even
when disguised with an image extension — checked by real file content,
not the extension — an oversized file is rejected, deleting media removes
the stored file, site settings exist by default with no setup step and
updating them never creates a second row, and the primary color field
rejects a non-hex value), and Integrations (the seeded payment gateway
catalog is available and disabled by default, enabling a gateway and
saving credentials never echoes them back in the response and stores them
encrypted rather than as plaintext in the database, a webhook's signing
secret is only ever revealed at creation and omitted from every later
listing, only a fixed set of known events is accepted, vouchers generate
in a batch with genuinely unique codes, a voucher can only be redeemed
once, and two Wi-Fi zones in the same branch can't share a name), and
Reports (sales totals/by-day/by-source with other branches and out-of-range
orders excluded, day boundaries following the branch timezone rather than
UTC, draft/cancelled/refunded orders not counting as revenue, the CSV
export's header/rows/total, product and category aggregation excluding
cancelled orders, delivery outcomes/average time/null-not-zero when empty,
top spenders and loyalty totals, the branch comparison respecting which
branches a user may see, rejected date ranges, and the `reports.view`
permission on every endpoint), and Administration (a user can't be given a
role or branch their creator doesn't hold, can't edit their own access or
delete themselves, is invisible to admins they share no branch with, loses
all tokens on deactivation / password reset / delete; roles can't exceed
the editor's own permissions, Super Admin is immutable and a role in use
can't be deleted; branch codes are unique and normalised; the activity log
is append-only, branch-scoped for non-Super Admins and filterable; and a
disabled account or a refresh token used as a bearer is rejected). This is
a solid starting suite, not exhaustive coverage — extend it alongside
each new module per rule 23.

### Frontend tests
```bash
cd frontend
npm install
npm test
```
Vitest, run against pure logic only (formatting and the Dhaka date helpers,
the navigation config's own invariants, the API client's error-handling
and download paths, and the administration helpers) — no component
or E2E tests yet. Adding React Testing Library / Playwright coverage for
the POS cart flow and the KDS board is a good next investment.

## What's NOT built yet (by design — see the phased delivery strategy)

batch/expiry tracking, Gift Cards, customer segmentation, marketing
Campaigns (multi-step, scheduled), real notification delivery (SMS/push/
email — see the gap note below), live GPS rider tracking and delivery-time
estimates, SEO beyond per-page meta fields, live
payment processing and live webhook/router delivery (see the gap notes
below), charts and scheduled/emailed reports (Phase 9 ships tables and a
sales CSV only), a self-service "my profile / change my
password" page, Security/backup/audit hardening beyond what Phase 10 added,
mobile apps
(Flutter — most notably a real Rider app; today a rider's status/progress
can only be updated from the admin console), offline POS queue.

Each of these is already scaffolded as a disabled nav item tagged with its
phase number in `frontend/src/config/navigation.ts` — extend that file and
follow the same pattern as `orders/`, `menu/`, `pos/`, or `inventory/`
(types → API hook → page → matching Laravel controller/policy/migration)
when you're ready for the next phase.

## Known gaps to close before this is production-ready
- No CI/CD, Docker, or environment-specific config beyond `.env.example`
- Refresh token is read from `sessionStorage` as a dev fallback; production
  should issue it as an HttpOnly cookie from the API instead
- Login/refresh are rate limited (Phase 11); no MFA yet, and no global API rate limit
- **KDS polls every 5 seconds instead of pushing over a WebSocket.** This
  works but doesn't scale well past a handful of concurrent kitchen screens
  and adds latency the architecture doc doesn't want (§9 calls for real-time
  sync). Swap `useKitchenTickets`'s polling for a socket subscription once
  the real-time layer (a later phase) exists.
- **No offline POS queue yet.** The architecture (§8) calls for an offline
  durable queue with auto-sync and conflict handling for the POS; the
  current POS page requires a live connection to `/orders`. This is real,
  not-yet-addressed scope for a connectivity-unreliable restaurant floor.
- POS still doesn't support split/partial payment, discounts entered at
  checkout, or cash-drawer workflow (§8) — `discount_total`/`delivery_fee`
  are accepted by the API but there's no UI for them yet
- **A received purchase can't be cancelled/returned.** `PurchaseController@
  cancel` only accepts a `draft` purchase — unwinding one that already
  landed stock (a supplier return, a data-entry mistake caught late) needs
  its own reversal flow with its own ledger entries, which this pass
  doesn't build.
- **Recipe consumption still draws from a single "default" warehouse**,
  never asking the cashier which storage location a sale should draw from.
  This was a deliberate simplification (checkout shouldn't ask that
  question) but it does mean stock held only in a non-default warehouse
  won't be found by POS consumption checks — keep sellable ingredients in
  the default warehouse, or transfer them in, until this gets smarter.
- **Notifications don't actually notify anyone.** `/notifications` is an
  internal record only — no SMS/push/email provider is wired up, so
  "sending" one just marks a database row `sent`. The page says this
  explicitly. Real delivery needs Firebase/FCM and/or an SMS gateway,
  which is Integrations scope (architecture §5 / Phase 8), not Growth.
- **Loyalty points can only be earned or manually adjusted — not redeemed
  at checkout.** The rate is also a fixed global constant (1 point per 100
  BDT) hardcoded in `OrderController@store`, not a per-branch setting. A
  customer's balance is real and correctly maintained, but there's no
  "pay with points" flow at POS yet — that needs its own careful design
  (how redeemed points interact with a promo code on the same order,
  whether partial redemption is allowed) rather than being bolted onto an
  already-complex checkout path.
- **Reviews have no public-facing display or customer-submission flow.**
  This pass only builds the admin side (an internal `POST /reviews` plus
  moderation) — a customer-facing website/app to actually leave a review
  is Website/CMS or a future customer-app scope, not built here.
- **Delivery zones are named areas with a flat fee, not geographic
  boundaries.** There's no map, polygon, or address-to-zone auto-matching
  — a cashier picks the zone by name from a dropdown at checkout. Real
  boundary-based zone lookup would need a maps/geocoding integration,
  which is Integrations scope (architecture §5), not Delivery itself.
- **No live rider location or delivery-time estimate.** The dispatch board
  shows where a delivery *is* in its handoff lifecycle (assigned/picked
  up/delivered) but not *where the rider physically is* — that needs GPS
  tracking from a rider-facing mobile app, which doesn't exist yet (riders
  are managed entirely from the admin console today; see "what's not
  built" above).
- **A failed delivery doesn't automatically do anything to the order.**
  This was a deliberate call (see `DeliveryController::fail()`'s comment)
  — a dispatcher decides whether to reassign a fresh attempt or cancel the
  order outright, rather than the system guessing at that business
  decision. Worth revisiting if failed deliveries turn out to need a
  faster default path.
- **Accounting is a revenue-vs-expenses summary, not a real accounting
  system.** No general ledger, no double-entry bookkeeping, no chart of
  accounts, no tax computation, no accrual accounting — `AccountingController
  @summary` deliberately answers "how much came in, how much went out" from
  data that already exists (Orders, Expenses) rather than inventing a
  parallel bookkeeping model. A restaurant that needs real financial
  statements for tax/audit purposes still needs a proper accounting
  package; this is a lightweight operational view, not a replacement.
- **Payroll's absence deduction is a flat daily-rate calculation** (monthly
  salary ÷ 30 × absent days) — no overtime, no per-hour rates, no tax
  withholding, no statutory deductions (provident fund, etc.), and no
  half-day-specific partial deduction (a `half_day` attendance status is
  trackable but doesn't currently affect payroll math — only `absent`
  does). Real Bangladeshi payroll compliance (tax slabs, EPF/PF rules)
  needs dedicated work this pass doesn't attempt.
- **An employee's login access (the `user_id` link) has no UI to set it
  yet.** The column and relationship exist and are respected by the API,
  but the Employees screen doesn't yet offer a way to link an employee
  record to an existing system User — worth adding once there's a real
  need to see "which staff members can log in" from the HR side.
- **No gateway actually processes a payment.** `PaymentGatewayController`
  stores which gateways are enabled and their credentials (encrypted at
  rest), but there is no bKash/Nagad/SSLCommerz SDK wired into checkout —
  enabling a gateway here is a config step for future integration work,
  not a working "customer pays online" flow yet.
- **Webhooks are configuration only — nothing is dispatched.** Creating a
  webhook subscription doesn't cause any HTTP request to fire when its
  event actually happens; that needs a queued delivery worker with retries
  and payload signing, which this pass deliberately doesn't build (see
  `Webhook`'s docblock).
- **Wi-Fi zones/vouchers are bookkeeping, not a live captive portal.**
  Generating and redeeming a voucher code here doesn't actually grant
  network access — per the architecture doc's own warning (§6), that needs
  real router/captive-portal infrastructure (e.g. a MikroTik RouterOS API
  call), which is out of scope for a web admin console.
- **The CMS page editor has no live preview or version history.** A page
  is either the current draft or the current published version — there's
  no "preview before publish" render and no rollback to an earlier
  version, both of which the architecture doc's Website Integration
  section calls for (§19 "preserve versions... allow rollback").
- **Media Library only accepts images**, not video — the architecture's
  Media Library scope includes video, which needs its own size/streaming
  considerations this pass didn't take on.
- **Reports are computed live, with no caching or pre-aggregation.** Every
  request runs real queries against `orders`/`order_items`/etc., so a very
  busy branch will feel it on a wide range. The 366-day cap bounds a single
  request, and the sales-by-day, delivery and rider figures are bucketed in
  PHP (so they work on MySQL, PostgreSQL and SQLite alike) over every
  matching row. If this becomes slow, the next step is a nightly
  summary table or a read replica, not more query tuning.
- **Reports have no charting library.** Tables with proportional bars only;
  no line/bar/pie charts, no drill-down from a figure to the orders behind
  it, and no comparison against a previous period.
- **Only the sales report exports to CSV.** The other five reports are
  on-screen only, and there is no PDF/Excel export or scheduled/emailed
  report.
- **Reports rely on the storage timezone being known.** Day boundaries are
  taken in the branch's timezone and converted to `config('app.timezone')`
  for querying, which is correct as long as timestamps are stored in the
  app timezone (Laravel's default). Changing `app.timezone` on a database
  that already has data would shift historic report days.
- **Report definitions are simple on purpose.** Revenue is order
  `grand_total` (so it includes tax and delivery fees); the category report
  sums item line totals (so it excludes order-level discounts, tax and
  fees, and won't add up to total revenue); "net" in the branch comparison
  is revenue minus recorded expenses, not a profit-and-loss; delivery time
  runs from rider assignment to delivery; delivery and top-spender figures
  are keyed to the day the order/assignment was created, not the day it
  finished. There is no cost-of-goods or margin reporting, because dishes
  carry no cost price.
- **Top products group by the item name stored on the order line.** If an
  item is renamed mid-range, its before and after sales show as two rows.
- Test coverage is a solid starting suite, not exhaustive — see above for
  what's covered on both sides
- **Phase 10 (Administration) gaps**
  - *The last-active-Super-Admin guard is defense-in-depth.* Because a Super
    Admin can't deactivate or delete themselves and only a Super Admin can
    manage one, the guard is practically only reachable through a race
  - *User and role changes are logged without a branch*, so only a Super
    Admin can read them in the Activity Log (a branch-level admin sees
    entries recorded against their active branch only)
  - *Branches can be created and edited, not deleted or deactivated*, and
    `is_active` on a branch isn't enforced anywhere yet
  - *No branch switcher exists in the UI*, so a branch created after someone
    signed in only appears in their `/auth/me` branch list after a re-login
  - *No self-service profile or password-change page.* A user's own password
    can currently only be changed by another administrator
  - *Users are soft-deleted, so a deleted user's email stays reserved.*
    Emails are stored lowercased. The password policy is a minimum of 10
    characters with letters and numbers; there is no breach-list check
  - *The audit table still needs `REVOKE UPDATE, DELETE` at the database
    level* on the application's DB role — the model-level guard doesn't
    stop someone with direct database access
  - *No MFA* — belongs to the security phase (login/refresh throttling landed in Phase 11)
  - *Settings is still an empty nav item*
  - *(Resolved in Phase 11: the PHPUnit suite has now been run and is green.)*

## Gaps closed in past passes
- **Session security holes found while building Phase 10** (found by
  inspection; verified by the suite in Phase 11). Before, a deactivated
  user's already-issued access token kept working until it expired, and the
  30-day *refresh* token was accepted as an ordinary bearer token on every
  API route. The new `EnsureActiveSession` middleware (`session.active`,
  on the whole authenticated route group) rejects a disabled account
  (`account_disabled`) and any token that isn't a full access token
  (`invalid_token_type`); `/auth/refresh` also refuses, and deletes the
  token of, a disabled or deleted user. Deactivating, resetting the
  password of, or deleting a user now revokes all their tokens
- **The audit log is now append-only at the model level.** `AuditLog`
  throws on any update or delete. (The database-level `REVOKE UPDATE,
  DELETE` is still a deployment task — see the gaps list.)
- **Two silent mass-assignment bugs, found while reading code for Phase 9**
  (found by inspection; verified by the suite in Phase 11). `Customer::loyalty_points` is not mass-assignable (on purpose),
  yet `LoyaltyLedgerEntry::record()` set it with `update()`, which Eloquent
  silently discards — so a customer's cached balance would never have
  moved off 0 and every ledger row's `balance_after` would have been
  wrong. It now uses `forceFill()->save()`. Likewise `Order` didn't list
  `cancelled_by`/`cancelled_at`/`cancellation_reason` as fillable, so
  cancelling an order saved the status but dropped the reason, actor and
  time (and the audit log recorded nulls). They are now fillable
  (server-set only) and `OrderCancelTest` asserts they persist. An audit
  of every other `update()`/`create()` against its model's `$fillable`
  found no further cases
- **Date defaults on Accounting and Attendance were off by a day for Dhaka
  users.** Both pages built "today" with `new Date().toISOString()`, which
  is the UTC date — so between midnight and 06:00 Dhaka time they showed
  yesterday, and Accounting's "start of month" could land on the previous
  month's last day. Both now use the Dhaka-date helpers added in
  `lib/format.ts` (covered by tests) that the Reports page also uses
- **Reservation double-booking**: `Reservation::hasConflict()` checks for
  overlapping time windows on the same table (excluding cancelled/no-show
  reservations) before `store`/`update` will save — covered by
  `tests/Feature/Tables/ReservationConflictTest.php`
- **Modifier UI**: the Food Item form (`/menu/items`) now has a full editor
  for modifier groups and their modifiers, for both create and edit, wired
  to the existing transactional backend sync
- **Automated tests**: PHPUnit feature-test suite on the backend and a
  Vitest unit-test suite on the frontend
- **Two silent partial-update bugs, caught while building Phase 5**:
  `SaveTableRequest` and `SaveIngredientRequest` marked fields like
  `branch_id`/`label`/`capacity` as unconditionally `required`, but the
  frontend's status-toggle and edit-form controls only ever sent the
  fields actually being changed (e.g. `{ status: 'occupied' }`) — meaning
  toggling a table's status or editing an ingredient's reorder level would
  have 422'd in real use. Both requests now only require the full field
  set on create (`$this->isMethod('POST')`) and accept a partial payload
  on update; `SavePromotionRequest` was written the same way from the
  start so as not to reintroduce the same bug.

## Phase 4 additions
- Ingredients, Suppliers, Purchases, Stock Adjustments/Waste — full CRUD +
  ledger, both API and UI
- Recipes: a food item can now declare what it consumes, and placing an
  order actually debits that stock automatically (see
  `tests/Feature/Inventory/RecipeConsumptionTest.php`)
- `stockAlerts` and `openKitchenOrders` on the dashboard, previously
  stubbed at `0`, now run real queries

## Gap-closing pass: warehouses, PO workflow, checkout blocking
The three gaps called out after Phase 4 shipped are now closed:
- **Multi-warehouse + Stock Transfers**: stock is now tracked per
  ingredient *per warehouse* (`ingredient_stocks`), not per branch. Every
  branch gets a "Main Warehouse" automatically the first time it's needed;
  branch managers can add more and reassign the default from
  `/inventory/warehouses`. `StockTransferController` moves stock between
  two warehouses in the same branch and rejects the whole transfer upfront
  if the source doesn't have enough of any line — covered by
  `test_transferring_stock_moves_it_between_warehouses` and
  `test_a_transfer_is_rejected_when_the_source_warehouse_lacks_stock`.
  Since this schema had never been deployed anywhere, warehouse support was
  folded into the original inventory migration rather than shipped as a
  fragile follow-up migration that alters an existing enum column.
- **Purchase draft/PO workflow**: `POST /purchases` now creates a `draft`
  purchase order with no stock impact; `POST /purchases/{id}/receive` is
  the step that actually lands stock (one ledger entry per line, in a
  transaction); `POST /purchases/{id}/cancel` abandons a draft that never
  arrived. Only a draft can be received or cancelled — see the "known
  gaps" note above about purchases that already landed stock.
- **Recipe consumption now blocks checkout on insufficient stock, and the
  block is race-safe.** `StockLedgerEntry::record()` takes an
  `allowNegative` flag; when POS checkout calls it with `allowNegative:
  false`, the zero-floor check happens *inside* the same row lock that
  performs the decrement — so two checkouts racing for the last portion of
  an ingredient can't both pass a "looks like enough" check and jointly
  oversell it. An oversold order is rejected with a 422 and nothing is
  created (no order, no items, no partial stock debit) — see
  `test_an_order_that_would_oversell_an_ingredient_is_rejected_and_nothing_is_created`
  and `test_a_recipe_shared_across_two_line_items_is_only_checked_against_their_combined_need`.
  This replaces the earlier "never blocks" design note — if a busy POS
  getting blocked by stock bookkeeping turns out to be a bigger problem
  than overselling, this is the one lever to revisit.
- 2 new backend test files (`StockLedgerTest`, `RecipeConsumptionTest`,
  9 test methods total) covering the ledger, waste/adjustment rules, the
  low-stock filter, and cross-domain recipe consumption

## Phase 5 additions: Customers & Growth
- **Customers**: CRUD with a unique phone number, order history per
  customer (`GET /customers/{id}/orders`), search by name or phone
- **Loyalty**: `LoyaltyLedgerEntry::record()` brings the same row-locked
  ledger discipline used for stock to loyalty points — a customer's
  balance can never drift from its ledger even under concurrent orders.
  Points are earned automatically on checkout (1 per 100 BDT of the final
  total) and can be manually adjusted with a required reason (for reversals
  or goodwill grants); see the gaps above for what redemption still needs
  before it's checkout-ready
- **Promotions/Coupons**: one model covers both, since a coupon is just a
  code-activated promotion. Validated server-side end to end at checkout —
  active window, minimum order amount, a discount cap on percentage
  promos, a global usage limit enforced by a race-safe conditional atomic
  increment, and a per-customer limit enforced by counting redemption rows
  — covered by 7 test methods in `PromotionTest`, including the max-discount
  cap and the expired/paused/exhausted rejection paths
- **Reviews**: start unpublished; `publish`/`unpublish` are explicit,
  audited moderation actions rather than a field anyone can flip
- **Notifications**: an internal announcement log only — deliberately not
  wired to any real delivery provider yet (see the gap note above)
- `orders.customer_id` — a column that predated the Customers table by
  four phases — finally has a real foreign key now that the table it
  always meant to point at actually exists
- 5 new backend test files (`CustomerTest`, `PromotionTest`, `LoyaltyTest`,
  `ReviewTest`, `NotificationTest`, 18 test methods total)

## Phase 6 additions: Delivery
- **Riders**: CRUD with a unique phone number and a quick status toggle
  (available/busy/offline); can't be deleted while they have an active
  (pending/assigned/picked-up) delivery
- **Delivery Zones**: named areas with a flat fee; can't be deleted once
  they have delivery history (mark inactive instead); a zone's fee is
  authoritative over any `delivery_fee` a client sends directly when
  placing an order — same principle applied to recipe pricing and promo
  discounts in earlier phases
- **Dispatch board** (`/delivery`): every delivery order and where it
  stands, with `assign`/`pickup`/`deliver`/`fail` actions. Reassignment is
  allowed from `pending`, `assigned` (swap riders), or `failed` (retry) —
  not once a delivery has been picked up. A rider is freed back to
  "available" automatically once they have no other open delivery,
  whether it succeeded, failed, or they were swapped off it
- **Deliberately doesn't duplicate the Order lifecycle**: `orders.status`
  already expressed `assigned`/`picked_up`/`delivered` since Phase 1, so
  `delivery_assignments` only tracks what's genuinely delivery-specific
  (rider, zone, handoff timestamps, proof-of-delivery notes, and a
  `failed` outcome the general Order lifecycle has no equivalent for) —
  per Non-Negotiable Rule #2, "don't duplicate a module that already
  exists elsewhere"
- Placing a delivery order now auto-creates a `pending` assignment so the
  dispatch board sees it immediately, before anyone has assigned a rider
- POS (`/pos`) gained a delivery zone picker and address field when
  "Delivery" is selected as the order type
- `orders.customer_id`-style debt repaid again: two silent partial-update
  bugs were fixed *while building* Phase 5 (Table/Ingredient); the same
  discipline (`$this->isMethod('POST')` gating which fields are required)
  was applied to `SaveDeliveryZoneRequest`/`SaveRiderRequest` from the
  start, and covered by a test (`test_a_riders_status_can_be_toggled_with_
  a_partial_update`) so it can't silently regress again
- 2 new backend test files (`DeliveryAssignmentTest`, `RiderAndZoneTest`,
  14 test methods total) covering the full assign→pickup→deliver→fail
  flow, rider availability bookkeeping, reassignment rules, and the
  zone-fee override

## Phase 7 additions: People & Finance
- **Employees**: a staff roster deliberately kept separate from `users` —
  not every employee needs a login (a kitchen helper or cleaner has no
  reason to touch the admin console), so an employee optionally *links to*
  a user account (`user_id`) rather than requiring one, same "extend, don't
  duplicate" reasoning Delivery applied to the Order lifecycle in Phase 6
- **Attendance**: a daily roster upserted on `(employee_id, date)` so
  correcting a day's mark never creates a duplicate row; the roster always
  shows every active employee, including ones nobody has marked yet
  (`status: null`), rather than silently assuming "absent" for someone who
  just hasn't been checked off — a subtle but deliberate UX/data-integrity
  choice
- **Payroll**: the standout cross-domain feature of this pass. `generate`
  computes each active employee's absence deductions from real
  `AttendanceRecord` rows within the exact period window (not an estimate,
  not user-entered), is safely re-runnable while still a draft (replaces
  rather than duplicates entries), supports a manual per-entry bonus, and
  moves through an explicit draft → finalized → paid lifecycle with a
  state guard at every step. Paying a period doesn't just flip a status —
  it creates a real `Expense` record for the total, under an
  auto-created "Salaries" category, so payroll costs actually appear in
  Accounting rather than living in their own silo
- **Accounting**: a revenue-vs-expenses summary for a date range, computed
  from real Orders and Expenses (not estimated) — deliberately framed as a
  lightweight operational view rather than a general ledger (see gaps
  above for what a real accounting system would still need). A
  system-generated expense (like the one payroll creates) can't be edited
  or deleted through the manual expense endpoints — same protective
  instinct as ingredients' `current_stock` never being directly settable
  back in Phase 4
- 3 new backend test files (`EmployeeAndAttendanceTest`, `PayrollTest`,
  `AccountingTest`, 13 test methods total) covering partial-update safety,
  the upsert-not-duplicate attendance rule, attendance-window-accurate
  payroll deductions, the full draft→finalized→paid lifecycle and its
  state guards, the automatic Expense creation on payment, and revenue
  correctly excluding cancelled orders

## Phase 8 additions: Digital Presence & Integrations
- **Website CMS**: pages are built from a fixed set of typed section
  blocks (hero/text/image/menu highlight/gallery/CTA) validated
  server-side against that list — an unknown section type, including
  anything resembling raw HTML, is rejected outright, per
  `02_CLAUDE_DEVELOPMENT_RULES.md` §8/§9's "template engine, not an HTML
  editor" rule. Publishing and unpublishing are separate, explicit,
  audited actions from saving a draft edit, so a half-finished page can
  never accidentally go live through a generic save
- **Media Library**: a real file upload to local disk storage (Laravel's
  `public` disk), not just metadata bookkeeping — validated by the file's
  actual content via the `mimes` rule (a text file renamed to `.jpg` is
  still rejected), size-capped at 5MB, and stored under a server-generated
  UUID filename so the client's original filename is never used as a
  storage path. Requires `php artisan storage:link` once after setup —
  see Setup above
- **Theme / Site Settings**: a singleton pattern (`SiteSetting::current()`)
  — the app always reads and writes the one earliest row, so there's no
  way to end up with two conflicting "site settings" records
- **Payment Gateways**: a fixed, seeded catalog (`PaymentGatewaysSeeder`)
  rather than letting the API mint arbitrary new gateway keys. Credentials
  are stored via Laravel's `encrypted:array` cast and never appear in any
  API response, including immediately after being saved — the frontend
  only ever learns whether a gateway `isConfigured`, never what its
  credentials are
- **Webhooks**: the signing secret is generated server-side and returned
  exactly once, in the creation response only — every later `index` call
  omits it, mirroring how a real API key is normally handled. Only a
  curated, validated set of event names is accepted
- **Free Wi-Fi Zones & Vouchers**: branch-scoped zones (Wi-Fi hardware is
  physically per-location) with batch voucher generation and a `redeem`
  action that can only ever mark a code used once
- **The typed API client gained real multipart upload support**
  (`apiRequest` now detects a `FormData` body and skips both JSON
  stringification and setting `Content-Type`, letting the browser set its
  own multipart boundary) — covered by a new test in `client.test.ts`
- 6 new backend test files (`CmsPageTest`, `MediaTest`, `SiteSettingTest`,
  `PaymentGatewayTest`, `WebhookTest`, `WifiVoucherTest`, 18 test methods
  total) covering the publish/unpublish split, slug uniqueness, rejecting
  unknown section types, real file-content validation (not just
  extension), the size cap, the singleton settings pattern, credentials
  never being echoed back and being encrypted at rest, the
  reveal-once webhook secret, and voucher code uniqueness plus
  single-use redemption

## Phase 9 additions: Reports & Analytics
- **One shared set of rules.** Every report counts the same orders
  (`draft`/`cancelled`/`refunded` excluded, soft-deleted excluded) and
  reads its `from`/`to` range the same way, in the branch's timezone — so
  an order placed at 02:00 on the 11th in Dhaka shows up on the 11th, not
  the 10th the way a plain `DATE(created_at)` on UTC timestamps would put
  it. Day bucketing and delivery averages run in PHP rather than with
  driver-specific SQL date functions, so the queries stay portable
- **Sales** (`GET /reports/sales`, `/reports/sales/export`): totals, a
  gap-free per-day series, and a by-source split; the CSV mirrors the
  per-day series plus a Total row, streamed rather than built in memory
- **Products / Categories**: ranked by revenue from the order line items
- **Delivery**: success rate is `delivered ÷ (delivered + failed)`, and
  both it and the average time are `null` (shown as "—") when there is
  nothing to compute, rather than a misleading 0
- **Customers**: new customers (same branch-or-chain-wide visibility rule
  as the customer list), top ten spenders for the range, and loyalty
  points earned/redeemed from the ledger
- **Branch comparison**: revenue, expenses and net per branch, limited to
  the branches the signed-in user is allowed to see, so `reports.view`
  alone never exposes another branch's numbers
- **Fixed along the way**: the Accounting and Attendance pages' UTC-date
  default bug, plus the loyalty-balance and order-cancellation
  mass-assignment bugs (see "Gaps closed in past passes"). The API client also
  gained a `parseAs: "blob"` option for file downloads, so the CSV export
  gets the same auth, token refresh and error handling as every other call
- 1 new backend test file (`ReportTest`, 13 test methods) and 5 new
  frontend tests (blob download ×2, Dhaka date helpers ×3)
- No new permission or migration: `reports.view` was seeded in Phase 1 and
  reports only read existing tables

## Phase 10 additions: Administration
- **Users** (`/users`, `users.manage`): list with `q` (name/email) and
  `status` filters, create, edit, reset password, delete. Passwords need 10+
  characters with letters and numbers and are `prohibited` on the regular
  update endpoint — only the dedicated reset endpoint changes one. Emails
  are lowercased. Route ids are matched against the users the caller may
  see, so an out-of-scope user is a `404`, not a `403` that confirms they
  exist
- **Privilege escalation is blocked in one place**, `App\Support\AdminAccess`,
  and every controller goes through it. A Super Admin is exempt. Anyone
  else can: grant only roles whose permissions are a subset of their own
  (never the Super Admin role); assign only branches they themselves have;
  see and manage only users who share one of their branches *and* whose
  roles they could grant. Nobody can change their own roles, branches or
  active flag, or delete themselves. Error codes: `cannot_assign_role`,
  `cannot_assign_branch`, `cannot_manage_user`, `cannot_modify_self`,
  `last_super_admin`, `invalid_active_branch`
- **Roles** (`/roles`, `/permissions`): create, edit, delete. The Super
  Admin role is immutable (`system_role`); a role containing permissions
  the editor lacks can't be edited (`role_exceeds_your_access`) and such
  permissions can't be added (`cannot_grant_permission`); a role that still
  has users can't be deleted (`role_in_use`). The permission list tells the
  UI which entries are `grantable` for the current user — a convenience;
  the server re-checks everything
- **Branches** (`/branches`, `branches.manage`): create and edit. Codes are
  upper-cased and must match `^[A-Z0-9][A-Z0-9-]{1,19}$`; timezones are
  validated against the IANA list. These drive the report day boundaries
  added in Phase 9
- **Activity log** (`/activity-logs`, `audit.view`): paginated and
  read-only — the `AuditLog` model refuses updates and deletes. A Super
  Admin sees every entry (optionally narrowed to one branch); everyone else
  sees only their active branch, or nothing without one
- **Session security**: see "Gaps closed" — disabled accounts and refresh
  tokens used as bearers are now rejected, and sensitive account changes
  revoke tokens
- **Shared helpers**: `App\Support\DateRange` (branch-timezone calendar
  dates → storage-timezone instants, now used by Reports too) and
  `App\Support\Like` (portable, escaped, case-insensitive `LIKE`, so
  search behaves the same on SQLite and PostgreSQL)
- 5 new backend test files, 46 test methods (`UserManagementTest` 16,
  `RoleManagementTest` 11, `BranchManagementTest` 7, `ActivityLogTest` 7,
  `SessionSecurityTest` 5) and 8 new frontend tests (`admin-utils`)
- No new permission or migration: `users.manage`, `branches.manage` and
  `audit.view` were seeded in Phase 1

## Phase 11 additions: Stabilization (first real execution)

Before this phase none of the backend had ever been executed. Booting it,
running migrations and the suite, and smoke-testing over HTTP surfaced real
defects, all now fixed:

- **Bootable skeleton**: `artisan`, `public/`, `config/*`, `storage/`,
  `bootstrap/providers.php`, `AppServiceProvider`, `.env.example`, `.gitignore`.
- **Everything 401'd**: a freshly created `User` had `is_active = null`
  (Eloquent doesn't apply DB defaults). 32 models now mirror their migration
  defaults in `$attributes`.
- **Sanctum stateful middleware removed**: it enforced CSRF (419) on browser
  POSTs from the frontend origin. This is a pure bearer-token API; CORS is
  configured via `config/cors.php` / `CORS_ALLOWED_ORIGINS`.
- **Cross-branch write hole**: a body `branch_id` was not checked against the
  caller's branches. New `AccessibleBranch` rule + `ScopedToBranch` trait on
  the write requests (11 tests).
- **500s on duplicate names** (expense categories, delivery/Wi-Fi zones,
  tables, ingredients, warehouses, categories, payroll periods) are now 422
  validation errors scoped per branch.
- **Portability**: low-stock query, `date` casts/outputs, `LIKE` search
  (`App\Support\Like`) no longer rely on SQLite/MySQL quirks; media file
  extensions come from detected content, not the client's filename.
- **Login hardening**: throttle (5/min per email+IP, 30/min per IP; refresh
  20/min per IP), equalised timing for unknown emails, case-insensitive email,
  `auth.login_failed` audit entries for real accounts, JSON 429/404 envelopes.
- **`php artisan chefs:create-admin`** for the first Super Admin.
- Tests: 188 → **200** (login throttle, branch write scope, admin command,
  `Like`, `DateRange`).
- Real HTTP smoke test via `php artisan serve`: health, CORS preflight from
  `http://localhost:3000`, login, `/auth/me`, refresh token rejected as a
  bearer (401), a browser-origin POST (201), wrong password (422), no token (401).

**Still not verified/known:** MySQL/PostgreSQL runs; Packagist install path;
money is computed with PHP floats (move to integer minor units or BCMath);
the seeded `Rider` role holds the broad `delivery.manage`; frontend was not
re-run against the live API in a browser.


## Phase 12 additions: Restaurant Core

**Money.** `App\Support\Money` does all arithmetic in integer paisa (no
BCMath, so it works on shared hosting): half-up percentages in basis points,
inclusive-tax extraction and largest-remainder allocation. DB columns stay
`DECIMAL(12,2)` and the API still speaks BDT numbers. Orders, promotions,
payroll and accounting now use it (no float drift).

**Server-side pricing.** `OrderPricer` prices every order from the database
only. Tax resolution per line: the dish's `TaxRate` if active → legacy flat
`tax_rate` (> 0, exclusive) → the branch default active `TaxRate` → 0. Tax is
computed on the pre-discount line total. `orders.tax_inclusive_total` records
the part already inside prices; grand total = subtotal + exclusive tax −
discount + delivery fee (never below 0). Business-rule failures return 422
`{code,message}`.

**Kitchen stations.** Dishes can be routed to a station; each order line
carries its own kitchen state and order state is the least-advanced line.
`?station_id=<uuid>|none` filters tickets and scopes bump/reopen to that
station. Unfiltered bump moves the whole order. Completing/cancelling frees
the table.

**Combos & deals.** Fixed-price bundles with an optional start/end window.
At checkout a combo is expanded into component lines (price allocated by
regular-price weights) so stock, station routing and tax work per dish. A
"deal" here means a combo's schedule window only — there are no auto-applied
happy-hour % discounts yet.

**Floor plans & waiter tabs.** Drag-and-drop editor (pointer events, grid
snap, clamped to the plan, shape/size). One open order per table; add rounds
(`POST /orders/{order}/items`) and move a tab (`POST /orders/{order}/transfer-table`).

**Security fix.** New `branch.models` middleware: route-bound records from a
branch the user cannot access return 404 (fixes an IDOR where records of
other branches could be edited by id). Chain-wide (null-branch) records need
only the route permission. `GET /pos/catalog` (needs only `orders.create`)
lets Cashier/Waiter load the menu without `menu.manage`.

**New permission:** `tax.manage` (Super Admin, Branch Manager). Re-run
`php artisan db:seed --class=RolesAndPermissionsSeeder` on existing installs.

**New routes/pages:** `/tax-rates`, `/kitchen-stations`, `/floor-plans`,
`/menu/combos`, `/waiter-orders`; KDS station tabs; POS now includes combos
and shows tables with open tabs; the food-item form has tax-rate and station
selects.

**Tests:** backend 266 tests / 1017 assertions (all passing, SQLite);
frontend 34 Vitest tests; `tsc` and `next build` pass.

**Known gaps / honest notes**
- A client-supplied manual `discount_total` is still accepted (clamped);
  permissioned discounts are planned for Phase 13.
- Promo codes are not re-evaluated when a round is added to a tab.
- `formatBDT` displays whole taka (rounds paisa for display only).
- MySQL is untested (tests run on SQLite); no `composer.lock` committed.
- The frontend was type-checked, unit-tested and built, but not exercised in
  a browser against a live API.
- Production target is MySQL on **shared hosting**: Reverb (long-lived
  process) is unsuitable there. Phase 14 will use Pusher/Ably for real-time,
  and a database queue driven by cron.


## Phase 13 additions: POS Completion

**Payments (ledger).** `payments` rows (`kind` payment|refund). Split/partial
tender = several payment rows on one order; the order is *paid* when the net
of the ledger covers `grand_total` (paisa arithmetic). `POST /orders/{order}/payments`
(`pos.access`): amount defaults to the balance, overpayment is refused (the
extra goes in as a **tip**, which is outside `grand_total`), cash needs an
open shift and `tendered >= amount + tip` (change is computed), and an
`Idempotency-Key` replay returns the original payment. A paid tab that gets a
new round becomes unpaid again; an order with payments cannot be cancelled
until refunded.

**Discounts (permissioned).** Hand-keyed discounts need `discounts.apply`
(capped at `POS_MAX_DISCOUNT_PERCENT`, default 20% of the goods owed) or
`discounts.override` (no cap, still never above what is owed), plus a reason;
audited. `PUT/DELETE /orders/{order}/discount` works only while the order has
no live payments. The old "any client-sent `discount_total`" path is closed:
at checkout it now requires the same permission, cap and `discount_reason`.
A percent discount is a fixed amount once applied (it does not grow when a
round is added).

**Refund / void.** `POST /orders/{order}/refunds` (`orders.refund`): reason
required, ≤ net paid, optionally against one payment, cash refunds need an open
shift. `restock: true` (full refund only, once per order) puts consumed
ingredient stock back through the stock ledger (`correction` entries).
Full refund → order `refunded`, table freed. `POST /payments/{payment}/void`
undoes a mistaken payment (a cash payment only while its shift is open).

**Cash register.** One open shift per cashier per branch: opening float, pay-ins/outs
(`cash.manage`), close with counted cash → expected, counted and variance are
stored; Z-report by method (sales, tips, refunds), movements, voids. Expected cash =
float + cash taken (incl. tips) − cash refunds ± movements. Only the opener (or
`settings.manage`) can change a shift.

**Printers & receipts.** `/printers` (`printers.manage`): network / USB / browser,
receipt or kitchen role, optional per-station kitchen printers, 58/80 mm,
drawer-kick flag. Network printer hosts must be **private LAN IPv4**
(loopback, link-local and public addresses are rejected — SSRF guard).
`GET /orders/{order}/receipt` returns a structured receipt (browser print page
at `/orders/{id}/receipt`, 80 mm print CSS). `POST /orders/{order}/print` builds ESC/POS
bytes; if the server can open a socket to the LAN printer it sends them
(`delivery: "server"`), otherwise it returns them base64 (`delivery: "client"`)
for a local print agent — which is the normal case on shared hosting.
Reprints are marked DUPLICATE and audited. `POST /cash/drawer/open` returns the
drawer pulse (audited).

**New permissions:** `discounts.apply` (Cashier, Branch Manager),
`discounts.override`, `orders.refund`, `printers.manage` (Branch Manager). Re-run
`php artisan db:seed --class=RolesAndPermissionsSeeder`. New migration
`…000022_create_pos_completion_tables`.

**Honest limits / not built**
- Thermal printouts are **ASCII only**: Bengali text and the ৳ sign can't be sent as
  text to ESC/POS (৳ prints as "Tk"); Bengali needs bitmap rendering (Phase 17).
- No direct USB/Bluetooth printing from the browser (WebUSB) and no bundled print
  agent yet — USB printers use the downloaded ESC/POS file or browser print.
- **Offline POS queue** (IndexedDB) from the roadmap is *not* built; it moves to a later phase.
- Split bill = several tenders on one order and an equal-share helper; splitting
  one order into separate per-seat orders is not built.
- Card/mobile-banking payments are recorded manually (reference/TrxID); live
  gateway integration is Phase 15.
- Promo codes still aren't re-evaluated when a round is added to a tab.
- The frontend was type-checked, unit-tested and built but not driven in a
  browser against a live API; MySQL remains untested.


---
## Phase 14 additions: Real-time & Notifications

**Real-time.** The API publishes `order.changed`, `delivery.changed` and `webhook.*` events to a
per-branch channel (`private-branch.<id>` on Pusher/Reverb, `branch:<id>` on Ably). The admin app
subscribes once (`RealtimeProvider`) and revalidates only the SWR keys an event affects
(`lib/realtime/events.ts`); a header badge shows live / offline. `REALTIME_DRIVER` =
`null` (default, nothing published) | `log` | `pusher` | `reverb` | `ably`. Publishing is a short
synchronous call that never queues and **never throws** — if the provider is down the order still saves
and the screens fall back to their normal polling/revalidation. Channel auth: `POST /realtime/auth`
(Pusher/Reverb, branch-checked) and `POST /realtime/ably-token` (Ably). **Reverb needs a long-running
process, which shared hosting doesn't give you — use Pusher or Ably there.**
The Ably browser bundle can't pass through Next's compiler, so `npm install` copies it to
`public/vendor/ably.min.js` (`scripts/copy-ably.mjs`) and it is loaded by script tag only when Ably is selected.

**Queue + scheduler (shared hosting).** `QUEUE_CONNECTION=database`; add ONE cron line:
`* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1`.
It drains the queue every minute (`queue:work --stop-when-empty --max-time=55`) and prunes old logs.
Without that cron line nothing queued (SMS, email, push, webhooks) is ever sent.

**Notifications.** `POST /notifications/{id}/send` now queues real deliveries per channel
(`push`, `sms`, `email`) instead of only flipping a status; it refuses (422 `no_recipients`) when nobody
can be reached. Every attempt is a row in `notification_deliveries` (retry with backoff, recipient masked
in the API) — `GET /notification-deliveries`, `POST …/test`, `POST …/{id}/retry`. Staff devices register at
`/device-tokens`. SMS drivers: `bulksmsbd`, `sslwireless`, `greenweb`, `alphasms`, `elitbuzz`, `twilio`
(`SMS_DRIVER`; BD numbers are normalised to `8801XXXXXXXXX`). Email uses Laravel mail (own-domain SMTP).
Push uses Firebase Cloud Messaging HTTP v1 (`FCM_PROJECT_ID`, `FCM_CREDENTIALS`); dead tokens are removed.
Optional order-status messages to customers: `NOTIFY_ORDER_SMS`, `NOTIFY_ORDER_PUSH` (both off by default).

**Webhooks.** Events are now actually delivered: JSON `POST`, header
`X-ChefsTrolley-Signature: t=<unix>,v1=<hmac-sha256("<t>.<body>")>`, `X-ChefsTrolley-Delivery` for
de-duplication, retries with backoff, an SSRF guard (https + public address only; set
`WEBHOOKS_ALLOW_INSECURE=true` for local development), a per-webhook delivery log and manual redeliver.
`GET /webhooks/{id}/deliveries`, `POST /webhook-deliveries/{id}/redeliver`.

**Permissions:** none new — `notifications.manage` and `integrations.manage` are reused.
New migration `…000023_create_realtime_notification_tables`.

**Honest limits / not verified**
- SMS gateway request shapes, FCM, Pusher and Ably calls follow each provider's public docs and are
  covered by tests with **faked HTTP only** — none has been run against a live account. Verify each with a
  real test message (Notifications → Delivery log → "Send a test") before relying on it.
- Real-time was not exercised against a live Pusher/Ably/Reverb server, and the frontend was type-checked,
  unit-tested and built but not driven in a browser.
- Customers have **no marketing opt-in/opt-out field** yet: SMS/email announcements go to every active customer
  with a phone/email on the chosen channel, and SMS costs money per message. Choose channels deliberately.
- Customer device-token registration is not exposed to customers yet (staff only); the customer app/website phases add it.
- The offline POS queue is still not built. MySQL is still untested (tests run on SQLite).
- The DNS check for webhook URLs needs working DNS on the server; unresolvable hosts are rejected at creation.


---
## Phase 15 additions: Gift Cards, Segments & Campaigns

**Gift cards.** `gift_cards` + an append-only `gift_card_entries` ledger (issue / redeem / refund /
adjust / void, signed amounts, running balance); every change locks the card row inside a transaction
and money is integer paisa. The 16-character code (no 0/O/1/I/L) is **shown once at issue and only its
SHA-256 hash is stored** — lists and receipts show `GC-••••last4`; lookup is throttled
(15/min). Sell: `POST /gift-cards` (`giftcards.issue`) — selling for cash needs an open shift and is
recorded as a cash pay-in so the drawer adds up. Spend: `POST /orders/{order}/payments` with
`method: gift_card` + `gift_card_code` (amount defaults to the lesser of balance due and card balance;
no tips from a card; expired/cancelled/empty cards are refused). Voiding such a payment, or refunding it
against that payment (`refunds_payment_id`), credits the same card. `GET /gift-cards` (`giftcards.view`)
reports the outstanding balance (what you still owe in food); `adjust` and `void` (`giftcards.manage`)
need a reason and are audited. Redeemable at any branch; `branch_id` is only where it was sold.

**Segments.** `customer_segments` hold a rule list + `all`/`any`. Fields are a fixed whitelist
(total spent, order count, average order value, days since last order / joined, loyalty points, has
ordered / phone / email) with `gte|lte|eq`; nothing from the request reaches SQL except bound values
(the numeric threshold is validated, then written as a literal because PDO binds floats as text and
SQLite then never matches). "Orders" are those that are `paid` or `partially_refunded` and not deleted.
`POST /customer-segments/preview` gives the live count, opted-out count, per-channel reachable counts and
a sample before anything is saved. All under `campaigns.manage`.

**Campaigns.** A campaign = segment (or everyone) + channels (push / SMS / email) + message, as a draft or
scheduled. **Send** queues through the Phase 14 notifier (retries, delivery log) and requires the sender to
confirm the audience count they saw (409 if it changed), refuses empty audiences and anything above
`CAMPAIGN_MAX_RECIPIENTS` (default 5000), and claims the campaign with an atomic status change so a double
click or two scheduler runs cannot send twice. Scheduled campaigns are sent by `campaigns:dispatch`
(every minute via the same cron line as the queue). Per-channel results show on the campaign.

**Consent.** New `customers.marketing_opt_out` (+ timestamp). Campaigns **and** the Phase 14
announcements skip opted-out customers. Every marketing email ends with a signed one-click unsubscribe
link (`GET /marketing/unsubscribe/{customer}`, idempotent, audited). Staff can toggle it on the customer
screen. This closes the Phase 14 "no opt-out" gap for email/push/SMS sent *from here*.

**Permissions (new):** `giftcards.view`, `giftcards.issue`, `giftcards.manage`, `campaigns.manage`
(Branch Manager gets all; Cashier gets view + issue). Re-run
`php artisan db:seed --class=RolesAndPermissionsSeeder`. New migration `…000024_create_marketing_tables`.
Frontend: Gift Cards, Segments and Campaigns pages, a "Gift card" tender in the POS payment panel, and the
opt-out checkbox on the customer panel.

**Honest limits / not built**
- Not built from the roadmap: the seven Bangladeshi payment gateways (Phase 15 in the roadmap) and SEO.
- Gift-card codes can't be re-displayed after issue (by design) and there is no re-issue/transfer flow;
  a lost card can only be cancelled and re-sold. No printable card layout beyond the browser print dialog.
- A gift-card payment is reversed by **Void** in the UI; refunding against a specific gift-card payment
  works through the API (`refunds_payment_id`) but the refund box does not offer it yet.
- SMS has no automatic "reply STOP" handling; SMS opt-out is only the flag staff set. Email unsubscribe
  links need `APP_URL` to be the public API URL, and were verified by tests, not in a real mail client.
- Segments have no birthday/dish/location rules and don't re-evaluate on a timer; they are computed when
  viewed or sent. Large chains should watch query time on the aggregate subqueries (tested on SQLite only).
- Campaign delivery depends on the Phase 14 cron + provider credentials; SMS/FCM/email were never run
  against live accounts. No A/B testing, open/click tracking or unsubscribe analytics.
- Frontend was type-checked, unit-tested (45) and built, but not driven in a browser against a live API;
  MySQL is still untested.


---

## Phase 15b additions: Seven Payment Gateways

bKash, Nagad, SSLCommerz, EPS, aamarPay, Pipra Pay and Bangla QR, behind one framework.

**How money is kept safe (this part is tested)**
- "Pay online" on an order creates a `payment_sessions` row whose amount is fixed **by the server**
  (never taken from the browser, capped at the order balance).
- The callback/IPN URL (`/api/v1/payment-callbacks/{gateway}/{session}/{token}[/ipn]`) carries an HMAC
  token. Nothing in the callback is trusted: it only triggers a **server-to-server verification** using
  the provider reference we stored ourselves.
- An order is credited only after the provider confirms paid, with the **exact amount and currency**,
  and **once** per session (idempotency key). A wrong amount, a payment arriving after the session was
  cancelled/expired, or a cancelled order puts the session in `review` instead of crediting it.
- If the order was paid another way meanwhile, the overpayment is flagged on the session.
- `gateways:reconcile` runs every 5 minutes (via `schedule:run`) to settle missed callbacks and expire
  stale sessions.
- Gateway secrets are never returned by the API; saving with a blank secret keeps the stored one; a
  gateway cannot be enabled without its required credentials.
- Migration: `2025_01_01_000025_create_payment_sessions`.

**Setup**
- `APP_URL` must be the **public** API URL (providers call it back). `FRONTEND_URL` is where the customer
  lands after paying (`/payment-result`, a public page). Optional: `GATEWAY_SESSION_MINUTES`,
  `GATEWAY_RETURN_URL`.
- Integrations → Payments: each gateway shows its own credential fields.
- Frontend adds the `qrcode` npm package (run `npm install`).

**Honest confidence per gateway — none has been run against a live provider account**

| Gateway | Confidence | Notes |
|---|---|---|
| bKash | medium | tokenized checkout (grant → create → status/execute), request/response shapes tested with fakes |
| SSLCommerz | medium | session API + validation by `tran_id` |
| aamarPay | medium | `jsonpost.php` + transaction check |
| Pipra Pay | low-medium | base URL configurable; field names from memory |
| Nagad | medium-low | RSA encrypt/sign round trip tested with local keys, not against Nagad |
| EPS | lowest | written from memory; verify every field against current EPS docs |
| Bangla QR | n/a | EMV payload + CRC verified (standard test vector); **no bank verification** — staff confirm by hand, recorded as manually confirmed |

**Known limits**
- No automatic gateway refunds: refunds are recorded in the ledger; return the money in the provider dashboard.
- Orders with no customer use placeholder phone/email when talking to providers.
- Provider sandboxes, MySQL, and the frontend in a real browser were not exercised. No `composer.lock` is shipped.


---

## Phase 16 additions: Security, MFA & Backups

**Sign-in & accounts**
- **Two-step sign-in (TOTP + 10 one-time recovery codes).** Works with any authenticator app. Secret and recovery codes are stored encrypted; a code can't be replayed; turning it off or making new codes needs password + a current code. Verified against the RFC 6238 test vector.
- Login with MFA is two calls: `POST /auth/login` returns `{mfaRequired, challenge}` (no tokens), then `POST /auth/mfa {challenge, code}`. Five wrong codes burn the challenge.
- **Lockout:** 5 wrong passwords/codes in a row lock the account 15 min (`LOCKOUT_ATTEMPTS`, `LOCKOUT_MINUTES`); the right password is refused while locked. Per-email/IP login throttling from earlier phases stays.
- **My Security page** (`/account/security`): change name/password (ends all other sessions), set up/disable MFA, list and revoke sessions ("sign out everywhere else"). Logout now ends the whole session including its refresh token.
- Admin can reset a colleague's MFA (`POST /users/{id}/mfa/reset`, audited) when a phone and codes are lost.
- **Breached-password check** (HaveIBeenPwned range API, only a 5-char hash prefix is sent; fails open if unreachable) on create/reset/change password. `SECURITY_BREACH_CHECK=false` turns it off. **Not exercised against the live service** (tests use a fake).
- Min. password 10 chars, letters + numbers (unchanged).

**Hardening**
- Security headers on every API response (nosniff, frame deny, no-referrer, restrictive CSP for the API, `no-store`, HSTS over HTTPS); baseline headers on the Next app. **No CSP on the Next app yet** (needs nonce setup and browser testing).
- Global per-user API rate limit (300/min) plus tighter limits on MFA and sensitive account actions.
- **Security review pass:** a test now fails if any API route outside the known public ones lacks authentication. Found and fixed: the dashboard "today's sales" figure was visible to every signed-in user; it now needs `reports.view`. Remaining notes: floor staff on a branch receive that branch's realtime events; a lockout message reveals that an account exists once it is locked.

**Backups** (`backups.manage` permission; Backups page)
- `backup:run` (nightly 02:30 via `schedule:run`) → database dump + uploaded media → zip → **AES-256-GCM encrypted** (chunked, detects wrong key / tampering / truncation) → saved on the server and, if `BACKUP_OFFSITE_DISK` is set, copied to that Laravel disk (S3/SFTP need their flysystem package, not installed). Failures are recorded and shown; a failed off-site copy makes the command exit non-zero.
- Database dump uses `mysqldump` when present, otherwise a built-in PHP dump (works on shared hosting). Sessions (tokens) are excluded.
- Retention (`backup:prune`, 03:30): every backup for 7 days, one a week for 4 weeks, one a month for 6 months; the newest is never removed.
- **Set `BACKUP_ENCRYPTION_KEY` and keep a copy off the server.** Without it (or with a rotated `APP_KEY` if you rely on the fallback) a backup cannot be opened.
- "Verify" = decrypt + check every checksum. It proves the file is intact, **not** that a restore works.
- **Restore runbook:** (1) `php artisan backup:decrypt storage/app/backups/<file>.zip.enc /tmp/restore` → `database.sql`, `media/`, `manifest.json`. (2) Put the app in maintenance mode (`php artisan down`). (3) Load `database.sql` into an **empty** database with the mysql client (`mysql db < database.sql`); copy `media/*` into `storage/app/public`. (4) `php artisan up`; everyone signs in again. Rehearse this on a copy before you need it — it has only been tested on SQLite here.
- **Audit log append-only:** `php artisan audit:protect` adds database triggers refusing UPDATE/DELETE on `audit_logs` (MySQL and SQLite). Opt-in because creating triggers needs the TRIGGER privilege on some hosts. `--remove` lifts it (needed to restore the table). On MySQL you can instead `REVOKE UPDATE, DELETE ON db.audit_logs` from the app user. FK `SET NULL` cascades are not blocked by the triggers.
- Migration: `2025_01_01_000026_create_security_and_backup_tables`.

**Not built / unverified:** forcing MFA for given roles; MFA via SMS/email; MySQL run of any of this; the Backups/Security pages in a real browser; large-database backup performance on shared hosting (the backup runs in one request/cron and holds each media file in memory while zipping).


## Phase 17 additions: Settings, Bangla, Light mode, Responsive, OpenAPI

**Settings** (`/settings`, permission `settings.manage`; API `GET|PUT /settings`, `GET /settings/public` for any signed-in user). Fixed keys only (unknown keys → 422), each validated, every change audited (`settings.updated`, before/after). Keys: `business.{name,legal_name,address,phone,email,tax_id}`, `locale.{default,timezone}`, `currency.{code,symbol,decimals}`, `receipt.{language,header,footer,show_tax_id}`, `features.{gift_cards,campaigns,reservations,wifi}`. Migration `…000027_create_app_settings` (also `customers.locale`).
- **Currency is display-only.** Money is always stored/calculated in BDT paisa; code/symbol/decimals change only what screens show (default 0 decimals; receipts always 2).
- **Feature flags** hide the menu item *and* the server refuses the module's routes with 403 `feature_disabled` (route middleware `feature:`), including paying with a gift card at the till. Data is kept when a module is switched off. Segments ride on the "campaigns" flag.

**Language (English + বাংলা).** Receipt labels and customer notifications come from `backend/lang/{en,bn}`; the notification language is the customer's `locale`, else the business default. Receipt JSON carries `locale`, `labels`, business profile fields; Bengali digits via `App\Support\Bn`. The admin UI switches language from the user menu (kept per device in `ct_locale`; business default applies until someone picks).
- **Coverage is partial, on purpose to be honest:** translated are the shell (menu groups and items, header, user menu), login, the Settings page, and receipts/notifications. Other pages still show English until their strings are moved into `frontend/src/i18n/{en,bn}.ts`; missing keys fall back to English.
- **The Bangla text was written by me, not a native reviewer.** Have someone fluent read `lang/bn/*` and `i18n/bn.ts` before going live.
- **Thermal (ESC/POS) printers print ASCII only** — Bangla letters become `?`. Bangla receipts work in browser/PDF print. Bitmap rendering is not built.
- **Bangla SMS** needs Unicode encoding: far fewer characters per segment (about 70 vs 160), so it costs more per message. Check your gateway's pricing.

**Light mode.** System / Light / Dark from the user menu (`ct_theme`), applied before first paint. Colors are CSS variables; the existing dark palette is unchanged. Light-mode text contrast has been eyeballed in screenshots, not measured with an accessibility tool.

**Responsive.** Below `md` the sidebar was invisible with no way to navigate; there is now a hamburger + slide-in drawer (Esc/backdrop/route change closes it). A real-Chromium pass (Playwright, mocked API) loaded every nav route at 390/768/1280px in dark and light: no horizontal overflow in the app shell on 47 of 52 routes. **Not measured:** `/dashboard`, `/reports`, `/finance/accounting`, `/waiter-orders`, `/cash-register` (the mock data was too thin for them to render) — and all pages were checked with *empty* data, so a wide table full of real rows may still scroll sideways. Material Symbols icons could not load in the sandbox, so icon appearance was not seen.

**OpenAPI.** `php artisan openapi:generate` writes `backend/docs/openapi.json` from the live router (253 routes): paths, methods, path params, bearer auth, `x-permission`, `x-feature`, request fields from FormRequest rules. `--check` fails if the file is stale. Contract tests: the committed spec equals the router, every route is documented with unique operationIds, and real `/settings/public` and error responses match hand-written schemas. **Limits:** response bodies are generic envelopes (only two endpoints are checked against real schemas); inline `$request->validate()` rules are not read, so those request bodies are undocumented.

**Not built / unverified in Phase 17:** MySQL run; translations of pages beyond those listed; accessibility audit beyond labels/focus on the new shell; customer-language field in the customers screen (API supports `locale`).


## Phase 18 additions: Docker, CI, health, MySQL review

See **`docs/DEPLOYMENT.md`** (shared hosting *and* Docker, health monitoring, MySQL notes).
- `backend/Dockerfile` (php-fpm 8.3, one image for api/queue/scheduler), `frontend/Dockerfile` (Next standalone), `docker-compose.yml` (dev) + `docker-compose.prod.yml`, `docker/nginx/default.conf`.
- `.github/workflows/ci.yml`: Pint, OpenAPI freshness, PHPUnit on SQLite **and MySQL 8**, tsc, vitest, `next build`, composer/npm audit, Docker image builds.
- `GET /api/v1/health` (+ Laravel `/up`); scheduler heartbeat so a stopped cron is visible.
- **Fixed from the MySQL review:** `DB_CONNECTION=mariadb` was not recognised by the audit-log trigger command or the backup dumper.
- **Code style:** the codebase had never been through Pint (~100 files differed). It was auto-formatted once (style only; all tests re-run green) so the CI gate can pass.
- `output: "standalone"` added to `next.config.mjs`.

**Not verified:** building the images, running compose, nginx syntax, **any MySQL run of the test suite**, the CI run itself (none possible in the authoring sandbox). `composer.lock` is still not committed — generate it on a networked machine and commit it; CI and the Dockerfile work without it but are then not reproducible.


## Phase 19 additions: Customer Website

A separate public Next.js app in **`website/`** (port 3001) plus a customer-facing API under `/api/v1/web/*`. Run: `cd website && cp .env.example .env.local && npm ci && npm run dev`.

**Pages:** home (CMS `home` page sections, reviews), menu (per branch, modifiers, combos), cart, checkout, phone login, my orders + live tracker, order detail (cancel, pay online, review), account + loyalty, table reservation requests, CMS pages `/p/{slug}`, `sitemap.xml`, `robots.txt`, Restaurant JSON-LD, English + বাংলা, brand colour from Settings.

**Sign-in = phone + SMS code.** 6 digits, valid 5 minutes, 5 tries, sent through the existing SMS gateway. Send limits: 60 s between codes, 3 per 15 min per number, 20 per IP per day. A new customer is created only after the code is proven. Customer sessions are a separate table (`customer_sessions`, 30 days, max 8 per customer); **a staff token does not work on customer routes and vice versa** (tested).

**Ordering (server-priced).** The site sends item ids, quantities, modifier ids, zone, address, phone, promo code. Prices, delivery fee, tax and discounts are all recalculated on the server; any price the browser sends is ignored. Stock is consumed exactly like POS orders. Idempotency-Key prevents double orders; max 5 open web orders per customer. **Ordering is OFF until you switch on Settings → Website → "Website ordering"** (also `web.cod`, `web.pickup`, `web.min_order`).
- Payment: cash on delivery/pickup, or an online gateway from Phase 15b (redirect → callback → back to `{WEBSITE_URL}/orders/{id}?payment=…`). Manual-confirm gateways (Bangla QR) are not offered on the website.
- Cancel is allowed only while the order is still pending, untouched by the kitchen and unpaid; stock is returned.
- Loyalty points for web orders are awarded when the order is completed/delivered (not when placed).
- Reviews: only for your own finished order, once, tags stripped, and hidden until a manager publishes them. Reservations are *requests* (status pending, no table held) and need the Reservations module on.

**Public reads** (`/web/site|branches|menu|reviews|pages|sitemap`) return only published/available data: no costs, recipes or staff fields (tested).

**Config:** `NEXT_PUBLIC_API_BASE_URL`, `NEXT_PUBLIC_SITE_URL` (website, baked at build); `WEBSITE_URL` and `CORS_ALLOWED_ORIGINS` (backend) must include the website origin. `docker compose up` now also starts `site` on :3001; CI builds/tests it.

**Verified:** backend suite 462 tests (17 new for the website), Pint, OpenAPI regenerated (275 routes); website: `tsc`, 7 vitest tests, `next build`, and a real-Chromium run (Playwright, **mocked API**) of browse → add with modifiers → sign in → checkout → order tracking at 375 and 1280 px: no horizontal overflow, no console errors. Admin: new "Website" card in Settings (vitest 49 pass).

**Not verified / known limits (be aware before launch):**
- The website was never run against the real Laravel API end to end, and the online-payment return flow was never run against a real gateway.
- Docker image for `website/`, MySQL, and the CI run were not executed (no Docker daemon/MySQL in the sandbox).
- **OTP SMS bodies are stored in `notification_deliveries`** (needed for the delivery log) — restrict who can read that table/screen, and consider purging old rows.
- The Bangla UI text and Bangla SMS text were written by me and need a native reader.
- Public menu responses are not cached on the server (only Next's 30–120 s fetch cache and the `web-public` 120/min/IP throttle protect the API).
- Order tracking polls every 15 s; there is no WebSocket for customers.
- CMS sections have only title + text (no images/gallery rendering), because the admin editor stores nothing else.
- No cookie/consent banner, no payment of the remaining balance for COD, no order-again, no address book.
- Offline POS queue, mobile apps (Phases 20–22) and deeper SEO (per-item pages, hreflang) are still unbuilt.


## Phase 19b additions: the leftovers from Phase 17–19

**Offline POS queue (admin).** If the connection is down (or the request fails at network level) when a cashier presses "Send order", the order is saved in this browser (`localStorage`, max 50) and re-sent automatically, oldest first, when the connection returns (also every 20 s and on the `online` event). It is re-sent with the **same Idempotency-Key** as the first attempt, so a request that actually reached the server before the link died can never become two orders; the server now also answers a duplicate-key race with the existing order. The server recalculates every price at replay time. A rejected order (e.g. a dish was removed) is **kept with the server's message** and needs Retry/Discard from a person. Orders replay only into the branch they were taken in. A banner shows offline state, waiting and rejected orders.
- **Not offline-capable, by design:** payments, discounts, refunds, cash register and printing (money must be confirmed live). The POS catalog must already be loaded: **reloading the page while offline does not work** (no service worker/offline cache is built).
- Queue is per device/browser; clearing site data loses unsent orders. Unit-tested (6 tests); **not exercised in a real browser against the real API.**

**Live order tracking (website).** With the Pusher/Reverb driver the server pings `private-order.{id}` (payload is only `{stage}`), the page then reloads the order through the normal API; channel auth signs only the customer's *own* web order (tested). Without push (driver none/Ably/log) the page polls `GET /web/orders/{id}/status` (ETag → 304): 5 s for 10 min, then 15 s, 30 s in a background tab, backoff on errors. Push was only tested server-side (signature, channel, payload); the browser Pusher client was **never connected to a real Reverb/Pusher server**.

**SEO.** Every page now has its own URL per language (`/en/...`, `/bn/...`); bare URLs 308-redirect (cookie → Accept-Language → `NEXT_PUBLIC_DEFAULT_LOCALE`). `hreflang` + canonical on every public page, `x-default`, sitemap with per-language alternates, dish pages `/{lang}/menu/{slug}` (canonical, OpenGraph, schema.org `MenuItem` + `Offer`), `Menu` JSON-LD on the menu, Restaurant JSON-LD with `sameAs`. Private pages are `noindex` and disallowed in robots.txt. **Honest limit:** only the interface language changes — menu names, descriptions and CMS text are written once by staff, so a Bangla page shows the same dish names. hreflang is correct but the SEO value is limited until the CMS/menu get real translations. JSON-LD uses BDT when the currency isn't loaded. Not checked with Google's Rich Results test.

**CMS images.** `image` and `gallery` blocks (and a hero background) now work end to end: admin picker over the media library, API validates media ids, the public API returns only whitelisted keys and URLs of existing **images** (PDFs/unknown ids dropped; tested).

**Order again / address book / notice.** `GET /web/orders/{id}/reorder` rebuilds the cart from *today's* menu and lists skipped items (unavailable, modifier gone); the order is still re-priced on placement. Saved addresses (max 10, default mirrored to `customers.address`, owner-only, tested) with a picker at checkout. The cookie banner is an **information notice, not a consent manager**: the site stores only sign-in, cart and language (no analytics/ads). Add a real consent tool before adding any tracker.

**Verified:** backend 469 tests (+7), Pint, OpenAPI 283 routes; admin 55 vitest; website `tsc`, 9 vitest, `next build`, Chromium (mock API) at 375/1280 px incl. language switch, order-again, item page, redirects, hreflang, sitemap: no overflow, no console errors.
**Not verified:** admin POS offline flow in a browser; Docker/MySQL/CI (no daemon); real gateway/Reverb; Bangla strings need a native reader.


## Phase 20 additions: Rider app (`rider/`)

A phone-first **PWA** (Next.js, port 3002) for delivery riders. Flutter was not used because the build sandbox has no Flutter/Dart.

- **Sign-in:** a normal staff account with the **Rider** role (permission `rider.app`) through the existing `/auth/login` (MFA and refresh work). The admin **Delivery → Riders** form links a rider record to that login (`riders.user_id`). The Rider role no longer has `delivery.manage`: a rider cannot assign or reassign anyone.
- **Everything is scoped to the rider's own deliveries** (`/rider/*`, middleware `rider`); another rider's delivery id is a 404. The rider can only pick up, deliver or report failure; cancelled orders cannot be picked up or delivered.
- **Cash on delivery:** the amount due is computed by the server from the order ledger. The rider ticks "I collected exactly ৳X" and the app sends that figure only as a confirmation; a wrong figure is refused (`cash_mismatch`), never recorded. Delivery creates a cash payment and a `rider_cash_collections` row. The cash is "held by the rider" until a manager with `cash.manage` settles it (`POST /cash/rider-holdings/{rider}/settle`), which needs the manager's own open shift in that branch and then counts in that shift's expected cash.
- **Live location:** the rider turns it on, only while carrying an order; fixes are throttled (15 s minimum, 25 m movement or 60 s heartbeat; server limit 60/min). It stops by itself when the last delivery closes. The dispatcher API shows a position only while sharing and fresher than 10 minutes.
- **Push:** assigning a delivery queues a `push` notification to that rider's registered devices (Firebase, server side through the existing notifier). The app registers its token through `POST /device-tokens`, and removes it on sign-out.
- Bangla/English, audit log entries for every pickup/deliver/fail, 10 new backend tests (ownership, lockout, cash mismatch, settle, cancelled, location).

**Upgrade steps for an existing database:** `php artisan migrate` and re-run `php artisan db:seed --class=RolesAndPermissionsSeeder` (adds `rider.app`; it resets the Rider role to that one permission). Then create/link rider logins in the admin.

**Verified:** backend 479 tests, Pint, OpenAPI 295 routes; rider `tsc`, 8 vitest, `next build`; admin `tsc`; Chromium at 390 px with a mock API: sign-in, list, pickup, deliver button locked until the cash box is ticked, cash tab, no console errors.
**Not verified:** a real phone, real GPS or a real Firebase project (push has never been delivered end to end, and the service worker never received a real FCM payload); Docker/MySQL/CI (no daemon). Browsers pause GPS and the service worker when the screen is locked or the app is in the background, so location sharing works only while the app is open (a screen wake lock is requested, best effort). Rider actions have no offline queue on purpose: money and state need a live connection. Tokens are kept in localStorage. Bangla strings need a native reader.


## Phase 21 additions: Staff app (`staff/`)

A phone/tablet-first **PWA** (Next.js, port 3003) for floor and kitchen staff. Same sign-in as the Rider app (staff account + password, MFA works). Tabs appear by permission:

- **Tables (waiter, needs `orders.create`):** table grid with open tabs, take or add to a dine-in order from today's menu (required modifier groups enforced), "Ready to serve" list with a **Served** button. Prices on screen are an estimate; the server prices the order. Each cart submission carries an `Idempotency-Key`, so a double tap or a retry on a weak network cannot create two orders.
- **Kitchen (needs `kds.access`):** station filter, tickets that turn amber/red with age, Accept → Start → Ready, and Undo (one step back). Same API and rules as the admin KDS.
- **Me (attendance):** the employee clocks in and out. **Times come from the server clock**, a client-sent time is ignored, a day the manager marked leave/absent can't be overridden, and one record per day. History of the last 14 days.
- **Push:** when the whole order becomes ready, the waiter who took it is pushed (`order_ready`). Registration and sign-out use the same `/device-tokens` flow as the Rider app.

Backend additions: permission `staff.app` (given to Waiter, Kitchen Staff, Branch Manager), middleware `employee` (the signed-in user must be linked to an active employee; that record decides the branch), `/staff/me`, `/staff/clock-in`, `/staff/clock-out`, `/staff/attendance`, `POST /staff/orders/{order}/served` (ready lines only, own branch only, audited). A login can now be linked to only one employee. Admin: **HR → Employees** and **Delivery → Riders** forms have a login selector (needs `users.manage` to list users).

**Upgrade steps:** re-run `RolesAndPermissionsSeeder` (adds `staff.app`; it resets those roles' permissions to the seeded set, so re-apply any custom edits). Create staff users, then link each in HR → Employees. No new migration.

**Verified:** backend 485 tests, Pint, OpenAPI; admin `tsc`; staff `tsc`, 6 vitest, `next build`; Chromium at 390 px with a mock API through sign-in, serve, order with a required option, KDS bump and clock-in: no console errors.
**Not verified:** a real phone or tablet, real Firebase push, a real kitchen at rush hour; Docker/MySQL/CI. No offline queue on purpose (an order sent twice later is worse than a clear "no connection"). The "Me" tab shows the server's date in its timezone, so set the app timezone correctly. Bangla strings need a native reader. Not included: pay/close a bill from the staff app (cashiers use POS), table transfer/merge in the staff app (API exists, admin only).


## Phase 22 additions: Customer app (`customer-app/`)

The installable ordering app, a **PWA** (Next.js, port 3004), separate from the public website (`website/`, which keeps the SEO pages). It uses the same customer API (`/web/*`) and the same phone + OTP sign-in, so a customer has one account on both.

- **Order:** branch menu with categories, combos and required options; cart kept on the phone; checkout with delivery/pickup, delivery area, saved addresses, promo code, cash or online payment. The price on screen is an estimate: the server prices, taxes and discounts the order. Each checkout attempt carries one `Idempotency-Key`.
- **Live tracking:** the order page follows the stage (received → preparing → ready → on the way → done) through the realtime channel when the server offers one, otherwise a cheap conditional poll with back-off; it also shows the rider's name, and offers cancel (only before the kitchen starts), pay-now, rate-the-order.
- **Loyalty and again:** points and recent activity, "order again" rebuilds the cart from today's menu and lists unavailable items, address book, profile and language (Bangla/English).
- **Reservations:** request a table, see and cancel your requests.
- **Push:** order progress alerts to the customer's phone. New endpoint `POST/DELETE /web/device-tokens` (customer token only; a token belongs to one customer, so a shared phone moves it; only the owner can delete it). The sending side already existed and is **off unless `NOTIFY_ORDER_PUSH=true`** (needs FCM credentials and a queue worker).

**Not changed:** no migration. Re-running the seeder is not needed.

**Verified:** backend 486 tests, Pint, OpenAPI (302 routes); customer-app `tsc`, 4 vitest, `next build`; Chromium at 390 px with a mock API: sign-in, menu with a required option (button locked until chosen), checkout, order placed with an idempotency key, live stage change picked up by polling, account page: no console errors.
**Not verified:** a real phone or install-to-home-screen flow, real Firebase push, a real online-payment round trip, Docker/MySQL/CI. **Known limit:** after paying online, the gateway returns the customer to the **website's** order page (`WEBSITE_URL`), not the app; they can reopen the app and the order shows as paid. Cash on delivery stays inside the app. There is no offline mode on purpose (an order must be placed live). The default language follows the restaurant setting until the customer picks one. Bangla strings need a native reader.
