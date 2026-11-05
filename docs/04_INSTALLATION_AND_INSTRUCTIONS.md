# Installation & Instructions

## 0. Prerequisites
PHP 8.3 + Composer 2, MySQL 8/MariaDB, Node 20+, (optional) Docker. See `03_SERVER_REQUIREMENTS.md`.

## 1. Backend (Laravel API)
```bash
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env            # edit DB_*, APP_URL, CORS_ALLOWED_ORIGINS, FRONTEND_URL, WEBSITE_URL
php artisan key:generate
php artisan migrate --seed --force   # tables + roles/permissions + gateway catalogue + units
php artisan chefs:create-admin you@example.com --name="Owner" --generate   # prints a random password once
php artisan storage:link
php artisan config:cache && php artisan route:cache
```
Cron (every minute): `* * * * * cd /path/backend && php artisan schedule:run >> /dev/null 2>&1`
Queue on a VPS: `php artisan queue:work --tries=3` under systemd/supervisor (on shared hosting the scheduler handles it).

## 2. Web apps
Each app is built with its public API URL baked in at **build time** (rebuild when it changes).
```bash
cd frontend     && cp .env.example .env.local && npm ci && NEXT_PUBLIC_API_BASE_URL=https://api.example.com/api/v1 npm run build && npm start     # :3000
cd website      && NEXT_PUBLIC_API_BASE_URL=https://api.example.com/api/v1 NEXT_PUBLIC_SITE_URL=https://example.com npm ci && npm run build && npm start   # :3001
cd rider        # :3002   same pattern, plus NEXT_PUBLIC_FIREBASE_* for push
cd staff        # :3003
cd customer-app # :3004
```
Docker alternative: `docker compose up --build` (dev) or `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build`. More detail: `DEPLOYMENT.md`.

## 3. First-day setup (in the admin console)
1. Sign in with the admin created above; enable MFA (**Account → Security**).
2. **Branches** → check name/address/timezone. **Settings** → business name, currency, receipt text, ordering switches.
3. **Tax rates**, **Menu → Categories / Items / Combos / Recipes**, **Kitchen stations**, **Tables / Floor plans**, **Delivery zones**.
4. **Users → Roles**: create staff users with the right role. For riders/staff-app users, link the user in **Delivery → Riders** / **HR → Employees**.
5. **Printers**, **Integrations → Payments** (gateway keys, see `05_API_KEYS_AND_INTEGRATIONS.md`), SMS/push `.env` values.
6. **Settings → Website → Website ordering** ON only after SMS login codes and at least cash on delivery work.
7. Run a test order end to end: POS → kitchen → pay → refund; website order → tracking; rider delivery → cash settle.

## 4. Operations
- Backups: **Backups** page (create, verify, download). Restore runbook is in `README.md` (Phase 17 section): `backup:decrypt`, load SQL into an empty DB, copy media. Rehearse before you need it.
- Upgrades: put the app in maintenance (`php artisan down`), deploy, `php artisan migrate --force`, rebuild web apps, `up`. Take a backup first.
- Health: `GET /api/v1/health` (+ `X-Health-Token` for queue/scheduler detail).
- Logs: `storage/logs` (shared hosting, `LOG_CHANNEL=daily`) or stderr (Docker).

## 5. Developer commands
`vendor/bin/phpunit` (488 tests) · `vendor/bin/pint` · `php artisan openapi:generate --check` · `npm test` / `npx tsc --noEmit` in each web app.
