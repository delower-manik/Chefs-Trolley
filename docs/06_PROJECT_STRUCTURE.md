# Project Structure

```
Chefs Trolley Restaurant OS System Final/
├─ START_HERE.md                 map of the package and what is/is not built
├─ README.md                     full build history, per-phase notes, runbooks
├─ docker-compose.yml / docker-compose.prod.yml / docker/   nginx + php images, prod overlay
├─ .github/workflows/ci.yml      backend (SQLite), 5 web apps, npm audit, docker builds
├─ docs/                         this documentation set (01…10), DEPLOYMENT.md, PRODUCTION_READINESS.md
├─ backend/                      Laravel 12 API
│  ├─ app/Http/Controllers/Api/  admin API (orders, payments, cash, menu, inventory, HR, finance…)
│  │   ├─ Web/                   customer website/app API (/web/*)
│  │   ├─ Rider/  Staff/         rider-app and staff-app endpoints
│  ├─ app/Http/Middleware/       EnsurePermission, EnsureBranchScope, EnsureRouteModelsInBranch,
│  │                             AuthenticateCustomer, EnsureRider, EnsureEmployee, SecurityHeaders
│  ├─ app/Models/  app/Policies/  app/Observers/  app/Jobs/
│  ├─ app/Services/              OrderPricer, OrderLedger, OrderStock, DeliveryFlow, GiftCards,
│  │   ├─ Gateways/Drivers/      SslCommerz, Bkash, Nagad, AamarPay, Eps, PipraPay, BanglaQr
│  │   ├─ Notify/                SMS drivers, FCM, Order/Rider/Staff alerts
│  │   ├─ Realtime/ Webhooks/ Backup/ Marketing/ Security/ OpenApi/ Web/
│  ├─ app/Support/Money.php      integer-paisa money helper
│  ├─ config/                    backup, gateways, notify, realtime, security, pos…
│  ├─ database/migrations/       30 files → 75 tables;  seeders/ roles, permissions, gateways, units
│  ├─ lang/{en,bn}/              server messages
│  ├─ routes/api.php  routes/console.php (schedule)
│  ├─ tests/Feature/…            Security, Pos, Orders, Delivery, Web, Staff, Gateways, Inventory, …
│  └─ docs/openapi.json          generated API contract (use for Flutter/native clients)
├─ frontend/                     Admin console (Next.js 15, Tailwind): src/app/(dashboard)/…
│                                 pos, kitchen, orders, menu, inventory, hr, finance, delivery, cms, settings…
├─ website/                      Public site + ordering, /[lang]/… (en|bn), SEO, sitemap
├─ rider/  staff/  customer-app/ PWAs: src/app, src/components, src/lib (api, push, i18n),
│                                 public/{manifest.webmanifest, sw.js, icons}
```

## Key conventions
- API envelope: success `{data: …}`; errors `{code, message, errors?}` with stable `code` strings.
- All admin routes: `auth:sanctum` → active session → branch scope → route-model branch check → `permission:<key>`.
- Money: BDT decimals on the wire, integer paisa internally; never trust client prices.
- Frontend API clients are hand-written per app (`src/lib/api.ts`), tokens in `localStorage` (rider `ct_rider_tokens`, staff `ct_staff_tokens`, customer `ct_web_token`).
- Service workers (rider/staff/customer) handle push only; no offline caching by design.
