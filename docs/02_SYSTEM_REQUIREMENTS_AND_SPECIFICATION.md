# System Requirements & Specification

## 1. Functional scope
See `01_PROJECT_PROFILE.md` (modules) and `09_USER_MANUAL.md` (how each is used). The API contract is `backend/docs/openapi.json` (regenerate: `php artisan openapi:generate`; CI check: `--check`).

## 2. Roles and permissions (RBAC)
Permissions are string keys checked by route middleware (`permission:<key>`) and object policies; roles are editable in **Users → Roles**.

Keys: `orders.view orders.create orders.cancel orders.refund pos.access kds.access kitchen.manage tables.manage reservations.manage menu.manage inventory.manage delivery.manage customers.view customers.manage loyalty.manage promotions.manage reviews.view reviews.moderate notifications.manage hr.manage payroll.manage finance.manage tax.manage cash.manage discounts.apply discounts.override printers.manage giftcards.view giftcards.issue giftcards.manage campaigns.manage cms.manage integrations.manage branches.manage users.manage audit.view reports.view settings.manage backups.manage rider.app staff.app`

Seeded roles: **Super Admin** (all), **Branch Manager**, **Cashier**, **Waiter** (+`staff.app`), **Kitchen Staff** (+`staff.app`), **Rider** (`rider.app`). Re-running `RolesAndPermissionsSeeder` resets role permissions to these defaults, so do it on upgrades only after noting any custom edits.

## 3. Non-functional requirements
| Area | Specification |
|---|---|
| Money | Integer paisa in code, DECIMAL(12,2) in DB, never floats; server is the only source of prices and totals |
| Multi-branch | Every staff request is branch-scoped (`X-Branch-Id` header or user's active branch) after an access check; object-level checks block cross-branch IDs |
| Auth | Staff: Sanctum bearer tokens with refresh, optional TOTP MFA + recovery codes, session list/revoke. Customers: phone + SMS OTP, separate 30-day tokens (hashed at rest, 8-session cap). Rider/Staff apps need `rider.app`/`staff.app` and a linked Rider/Employee record |
| Rate limits | login/mfa/refresh, OTP 20/min/IP (+3 codes per 15 min per phone, 20 SMS/day per IP), public 120/min, orders 6/min |
| Integrity | Row locks on payments, refunds, cash shifts, rider cash, reservations, stock, gift cards; idempotency keys on orders and payments; unique key on gateway settlement |
| Audit | Append-only audit log for sensitive actions (cancel, refund, void, cash, settings, staff app actions) |
| Backups | Encrypted DB+media archives, scheduled, verify and download in admin, offsite disk option |
| Localisation | Website and customer app: English/Bangla; admin: English |
| Accessibility | WCAG 2.1 AA checked with axe (contrast, labels); tokens `text-primary-fg`, aria-labels on controls |
| Browsers | Current Chrome/Edge/Safari/Firefox; PWAs need HTTPS (service worker, push, GPS) |
| Real-time | Optional (Pusher/Reverb/Ably); without it the UI polls |

## 4. Client requirements
Admin/POS: any modern browser; POS terminal needs a 1280×720+ screen and (for ESC/POS) LAN access to the printer or the local print agent. Phones for PWAs: Android 8+/Chrome, iOS 16.4+/Safari for web push (installed to home screen).
