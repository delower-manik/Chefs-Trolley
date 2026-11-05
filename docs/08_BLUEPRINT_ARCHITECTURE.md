# Blueprint — Architecture

```
 Customers ─► website (3001) ┐
 Customers ─► customer PWA   ├──► /api/v1/web/*  (phone+OTP, customer tokens)
 Rider  ───► rider PWA       ├──► /api/v1/rider/* (Sanctum + rider.app + Rider record)
 Waiter/Kitchen ► staff PWA  ├──► /api/v1/staff/* (Sanctum + staff.app + Employee record)
 Cashier/Manager ► admin+POS ┘──► /api/v1/*       (Sanctum + permission:<key> + branch scope)
                                   │
                         Laravel 12 (controllers → services → models)
                                   │
        MySQL ◄─ queue(database/redis) ◄─ scheduler (cron)  ──► SMS · FCM · payment gateways · webhooks
                                   └─► realtime publisher (Pusher/Reverb/Ably | null→polling)
```

## Order lifecycle
`draft → pending → confirmed → accepted → preparing → ready → assigned → picked_up → delivered/completed`, or `cancelled` (allowed only up to `preparing`, and only with no net payments) / `refunded`; kitchen state per line `new → accepted → preparing → ready → served → completed`; customer-facing stage derived by `OrderStage::of()`; delivery: `assigned → picked_up → delivered | failed`.
Creation: price on server (`OrderPricer`) → stock debit by recipe (`OrderStock`, ingredient rows locked in id order) → promo caps (atomic) → kitchen tickets → observers publish real-time events and notifications. `Idempotency-Key` prevents duplicate orders/payments.

## Payment & cash
`PaymentController` locks the order row, then writes payments/refunds/voids (same lock order everywhere). Cash payments attach to the cashier's open shift; shift close computes expected cash/variance under a lock. Rider cash collections are settled into a shift by a manager. Online gateways create a `payment_session`; the callback is HMAC-token authenticated, re-verified server-to-server, amount-checked, and settled once (`gw:<session>` idempotency key).

## Security layers
Throttling · bearer tokens (hashed) · MFA · RBAC + object/branch checks · security headers · encrypted gateway credentials · audit log · SSRF-guarded webhooks · encrypted backups · customer vs staff token separation (tested).

## Extension points for native apps
Use `docs/openapi.json`; auth endpoints `/auth/login|refresh` (staff, rider), `/web/auth/code|verify` (customer); push token registration endpoints; real-time via the same Pusher/Ably channels (`/realtime/auth`, `/web/realtime/*`). The PWAs are reference implementations of each flow.

## Known design limits
No offline order queue in rider/staff/customer apps; POS needs connectivity; real-time optional; single-currency; per-IP rate limits require correct `TRUSTED_PROXIES`.
