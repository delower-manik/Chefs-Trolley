# Project Profile — Chefs Trolley Restaurant OS

| | |
|---|---|
| **Product** | Chefs Trolley Restaurant OS: one system for POS, kitchen, tables, delivery, inventory, HR/payroll, finance, loyalty/marketing, online ordering and CMS, for one or many branches |
| **Market** | Bangladesh-first (BDT, Bangla + English, bKash/Nagad/SSLCommerz/AamarPay/EPS/PipraPay/Bangla QR, BD SMS gateways), usable elsewhere |
| **Owner decisions** | Production DB: MySQL on shared hosting or VPS. SMS: BD gateways + Twilio. Push: Firebase (FCM). Real-time: Reverb/Pusher/Ably (optional, falls back to polling). Printers: ESC/POS (network/USB agent) and browser print |
| **Delivered surface** | 1 Laravel 12 API · 1 admin console · 1 public website · 3 installable PWAs (customer, staff, rider) |
| **Not delivered** | Native Flutter apps (customer iOS/Android, staff, rider) and a separate Android POS-terminal app. Flutter/Dart was unavailable in the build environment. The PWAs and the documented REST API (`backend/docs/openapi.json`) are the starting point for them |
| **Size** | 302 API routes · 75 database tables · 488 backend tests · 55 admin unit tests |

## What it does (by module)
Orders & POS (dine-in, takeaway, delivery, counter; split/partial payments, tips, refunds, voids, gift cards, discounts with approval) · Kitchen display and stations · Tables, floor plans, reservations · Delivery zones, rider assignment, rider cash settlement · Inventory (ingredients, recipes, stock ledger, purchases, suppliers, transfers, adjustments, warehouses) · HR (employees, attendance, payroll) · Finance (expenses, accounting summary, tax rates) · Customers, loyalty, reviews, promotions, campaigns/segments, SMS/push/email notifications · CMS, site settings, public website with online ordering and live tracking · Wi-Fi vouchers · Webhooks · Backups (encrypted) · Audit log · Roles & permissions (RBAC), MFA, sessions.

## Apps
| App | Folder | Port | Users | Type |
|---|---|---|---|---|
| API | `backend/` | 8000 | all | Laravel 12, PHP 8.3, Sanctum |
| Admin console (incl. POS, KDS, waiter orders) | `frontend/` | 3000 | staff by permission | Next.js 15 |
| Public website + online ordering | `website/` | 3001 | customers | Next.js 15, Bangla/English, SEO |
| Rider app | `rider/` | 3002 | riders | PWA (Next.js 15) |
| Staff app | `staff/` | 3003 | waiter, kitchen, manager | PWA |
| Customer app | `customer-app/` | 3004 | customers | PWA |

## Honest status
Verified in the build sandbox: unit/feature tests, authorization matrix, dependency audit (0 known vulnerabilities in the 5 Next apps), secret scan, accessibility (axe, WCAG 2.1 A/AA) on 49 admin routes in both themes, Lighthouse a11y/best-practices/SEO, Playwright flows, independent security and concurrency reviews with fixes. **Not verified:** real devices, GPS, Firebase push, printers, MySQL, Docker, CI, HTTPS/PWA install, load. See `10_SECURITY_TESTING_AND_READINESS.md`.
