# Production readiness report (no devices, no hosting)

> Superseded by `10_SECURITY_TESTING_AND_READINESS.md` (final).

Date: 2026-10-31. Scope: everything that can be verified in the build sandbox. This is NOT a go-live certificate.

## Verified (with evidence)
| Area | Result |
|---|---|
| Backend suite | 488 tests / 3856 assertions pass (SQLite in-memory); Pint clean; `openapi:generate --check` up to date |
| Admin app | tsc clean, 55 vitest tests pass |
| Dependencies | `npm audit --omit=dev`: 0 vulnerabilities in all 5 Next apps (Next 15.5.28, postcss override, @firebase/* split, unused zod removed) |
| Secrets | Scan clean; no default credentials seeded (first admin via `CreateAdminCommand`) |
| AuthN | Every non-public route requires auth (existing test) — 15 public routes, all intentional |
| AuthZ matrix (new) | All 246 permission-gated routes refuse a signed-in user with zero permissions (403/404); staff tokens rejected on all 22 customer routes |
| Rider/Staff/Customer PWAs | Request-loop bug found and fixed (0 idle requests in 4s) |

## Findings fixed in this pass
1. **Dashboard leak** — `recentOrders[].total` was visible to any signed-in user (rider/staff app accounts); now requires `reports.view` (test added).
2. **Cash-shift races** — open (double shift), close (double close / missed concurrent payment), movement (drawer overdraw) now run under a row lock; rider-settle no longer writes an empty audit entry.
3. **Next.js 14.2 advisories** — upgraded to 15.5.28 with async params in the website.

## Known low-risk items (not changed)
- Route-model binding runs before the permission check, so a zero-permission user can tell an existing ID (403) from a missing one (404). IDs are UUIDs; no data is exposed.
- Any signed-in user of a branch can subscribe to that branch's realtime channel (payload is order id/status only).

## NOT verified — must be done before go-live
- Real phones, GPS, Firebase push, printers, HTTPS/PWA install.
- MySQL: migrations and locks were never run on MySQL (only SQLite). The new row locks are no-ops on SQLite, so the races are fixed by design but **not proven under real concurrency** — run a load test on MySQL.
- `composer audit` (Packagist blocked here), PHPStan/Larastan (not installed), Docker builds, CI.
- Accessibility (axe) and Lighthouse were not run; admin/website Playwright flows not re-run after the Next 15 upgrade.
- Independent agent security/concurrency reviews were not run.
- Bangla copy needs a native reader; no offline queue in Rider/Staff/Customer apps (by design).
- Re-running `RolesAndPermissionsSeeder` resets role permissions.
