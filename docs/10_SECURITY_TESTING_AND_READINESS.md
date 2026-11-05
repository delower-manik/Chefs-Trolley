# Security, Testing & Production Readiness (final)

Environment: build sandbox, SQLite test DB, real Laravel API + Next dev servers, headless Chromium. Date: 2026-11-02.

## Results
| Check | Result |
|---|---|
| Backend tests | 488 tests / 3856 assertions pass; Pint clean; OpenAPI up to date |
| Web apps | admin: tsc clean, 55 tests; website 9 tests; rider/staff/customer-app tests + builds (earlier phases) |
| Dependencies | `npm audit --omit=dev`: 0 vulnerabilities in all 5 Next apps (Next 15.5.28) |
| Secrets | scan clean, no default credentials |
| Authorization | all 246 permission-gated routes refuse a zero-permission user; staff tokens rejected on all 22 customer routes; 15 public routes are intentional |
| Accessibility (axe, WCAG 2.1 A/AA) | 49 admin routes × light & dark themes + website: all real violations fixed (contrast tokens, 95+ missing form labels). Residual: transient nav colour during theme transition in the test harness |
| Lighthouse (dev server) | website a11y 95–96, best-practices 96, SEO 91–92 (before fixes for meta description/cart label); admin login a11y 100. Performance scores NOT measured (dev server; fonts blocked in sandbox) |
| Playwright flows | website menu/item/sitemap/robots; admin login (bad password rejected), POS add item, logged-out redirect; 0 uncaught page errors |
| Independent reviews | security-auditor: no Critical/High. concurrency-specialist: no Critical; High/Medium items fixed (below) |

## Fixed during the audits
Dashboard order-total leak · cash shift open/close/movement races · order cancel vs payment race · payment idempotent-retry · void lock order · stock-lock ordering (deadlock) · reservation double-booking · rider pickup/fail races · trusted-proxy support (`TRUSTED_PROXIES`) · payment callback base URL from `APP_URL` (not the Host header) · contrast tokens and form labels · Next.js 14→15 security upgrade · rider/staff/customer request-loop bug.

## Known open items (recommended before heavy production use)
1. Per-customer promotion limit is checked outside the transaction (two parallel orders can both pass).
2. Web order placer: a same-Idempotency-Key race can end in an error instead of returning the first order; order code uses `count()+1001` (unique index prevents duplicates, but a collision can fail an order under load).
3. Loyalty earn check runs outside the customer lock (rare double-earn on repeated status events).
4. Gateway start can create two sessions on double-click (settlement stays safe; the extra is flagged overpaid).
5. OTP verify attempt counter is non-atomic; no per-phone verify throttle; OTP send can be abused to annoy a victim (capped).
6. Webhook SSRF via DNS rebinding (blind POST; response excerpt is stored).
7. Route-model binding runs before permission checks, so 403 vs 404 reveals whether a UUID exists.
8. `SegmentQuery` field/operator allow-list was not fully traced by the reviewer.

## Must be done on real infrastructure
- **MySQL**: run the full suite and a load test (the new row locks are no-ops on SQLite, so races are fixed by design but unproven). Rehearse backup/restore.
- **Docker/CI**: nothing has been built/run (no Docker daemon, no GitHub in the sandbox). Expect small fixes on the first run.
- **Devices**: phones (PWA install, GPS, push), ESC/POS printers, POS terminal hardware, HTTPS.
- `composer audit`, PHPStan/Larastan, production Lighthouse performance, accessibility of rider/staff/customer apps (only admin + website were scanned), Bangla copy review by a native reader.
- Penetration test and a payment-gateway sandbox test per provider before going live.
