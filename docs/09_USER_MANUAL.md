# User Manual

## Sign in
Admin console → email + password (+ 6-digit code if MFA is on). After 5 failed attempts you are throttled for a minute. **Account → Security**: change password, enable MFA (scan the QR with an authenticator app, store the recovery codes), see and sign out other sessions. Switch branch with the branch selector in the header (only branches you are assigned to).

## Owner / Manager
- **Dashboard**: today's orders, open kitchen orders, stock alerts; sales totals appear only with `reports.view`.
- **Menu**: Categories → Items (price, tax, kitchen station, availability) → Combos → Recipes (ingredient quantities drive stock deduction).
- **Inventory**: Ingredients, Warehouses, Purchases (receive stock), Suppliers, Transfers between warehouses, Adjustments (wastage/corrections; every change is in the stock ledger).
- **Tables / Floor plans / Reservations**; **Delivery**: zones (fee, minimum), riders, assignment board; **Cash register**: shifts and Z-report; **Finance**: expenses and accounting summary; **HR**: employees, attendance, payroll.
- **Customers / Segments / Campaigns / Promotions / Gift cards / Reviews**: loyalty, targeted SMS/push, coupons, moderation.
- **CMS**: pages, media, theme (site name, colour, logo). Keep the brand colour dark enough for white text (contrast ≥ 4.5:1).
- **Users → Roles**: assign permissions. **Activity logs**: who did what. **Backups**: create/verify/download. **Settings**: business info, website ordering switch.

## Cashier (POS)
1. **Cash register → Open shift** with the opening float.
2. **POS**: pick order type (Counter/Dine-in/Takeaway/Delivery), table or delivery zone, tap items, add modifiers/notes, apply a discount (needs `discounts.apply`), **Place order**.
3. **Take payment**: cash/card/mobile/gift card, split allowed, tip optional. Cash change is shown. A double-tap cannot charge twice.
4. Mistakes: **Void** a payment (cash only while your shift is open), **Refund** (needs `orders.refund`), **Cancel** an order (only before it is ready and only if nothing is paid; refund first).
5. **Pay-in/out** movements need a reason; you cannot pay out more than the drawer holds. **Close shift**: count the cash; the system shows expected cash and variance.
6. Receipts: browser print or ESC/POS printer (**Printers**).

## Waiter (Staff app, or admin Waiter Orders)
Sign in with your staff account. **Waiter**: open a table, add rounds of items, send to kitchen, mark **served** when the kitchen says ready (you also receive a push). **Attendance**: Clock in/out (uses the server clock; if the manager marked you absent/on leave you cannot override). Install: open the site in Chrome → *Add to Home screen*; allow notifications.

## Kitchen
**Kitchen / KDS** (admin) or the Staff app's Kitchen tab: tickets by station; bump a ticket through accepted → preparing → ready. Waiters are notified when it is ready.

## Rider (Rider app)
Sign in, **Share location** only while delivering (stops after the last delivery). For each delivery: **Pick up** → **Deliver** (enter cash collected for COD, recipient name/proof note) or **Fail** with a reason. Cash you hold is shown; hand it to the manager, who settles it in **Cash register → Rider holdings** into an open shift.

## Customer (website or Customer app)
Browse menu (English/Bangla) → item → add to cart → Checkout (delivery or pickup; choose cash or online payment) → sign in with phone number and the SMS code. **Track** the order live; **Order again**, loyalty points, saved addresses, reviews and **Reservations** are in **Account**. Online payment returns to the website after paying.

## Troubleshooting
| Symptom | Likely cause |
|---|---|
| Customers get no SMS code | `SMS_DRIVER`/keys wrong, no sender ID, balance empty, or per-phone limit (3 per 15 min) |
| Everyone gets "too many requests" | App behind a proxy without `TRUSTED_PROXIES` |
| Browser calls fail with CORS error | App origin missing from `CORS_ALLOWED_ORIGINS` |
| Push never arrives | Missing `NEXT_PUBLIC_FIREBASE_*` or `FCM_*`, site not on HTTPS, notification permission denied |
| Online payment stays pending | `APP_URL` is not the public API URL, or callback blocked by firewall; check **Integrations → Payments** |
| Queue/campaigns/backups do nothing | Cron `schedule:run` is not running (`/api/v1/health` with token shows `scheduler_last_seen_seconds_ago`) |
| Rider/staff cannot sign in to their app | User lacks `rider.app`/`staff.app`, or is not linked to a Rider/Employee record |
