# Deployment guide (Phase 18)

Two supported shapes. **Pick shared hosting if that is what you actually have** — it was the owner's stated production target.

## A. Shared hosting (MySQL/MariaDB, cPanel-style)

1. **Database:** create a MySQL/MariaDB database + user in the hosting panel. utf8mb4 / `utf8mb4_unicode_ci`.
2. **Backend** (`backend/`): upload, point the domain's document root (or a subdomain such as `api.example.com`) at `backend/public`. Run `composer install --no-dev --optimize-autoloader` (SSH or the panel's Composer; if you have neither, run it on your computer and upload `vendor/`).
3. Copy `.env.example` → `.env` and set: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `DB_*`, `BACKUP_ENCRYPTION_KEY` (random, 32+ chars — **store a copy somewhere safe, backups are unreadable without it**), `HEALTH_TOKEN`. Then `php artisan key:generate --force`, `php artisan migrate --force`, create the first admin with `php artisan chefs:create-admin` (see README).
4. **One cron line, every minute** (this drives the queue, campaigns, payment reconciliation, backups):
   `* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1`
5. `php artisan config:cache && php artisan route:cache`.
6. **Frontend** (`frontend/`): needs Node 20 to *build*. Build on your computer (`NEXT_PUBLIC_API_BASE_URL=https://api.example.com/api/v1 npm run build`) and host with Node (`.next/standalone` → `node server.js`) on a host that supports Node apps, or on Vercel/Netlify. Plain PHP shared hosting cannot run the admin console.
7. **Audit-log protection (optional):** `php artisan audit:protect` needs the MySQL `TRIGGER` privilege. On servers with binary logging enabled, creating triggers may also need `log_bin_trust_function_creators=1` (a host-level setting you may not be able to change). If it fails, use `REVOKE UPDATE, DELETE ON db.audit_logs FROM 'app_user'` instead.

## B. Docker (VPS / cloud)

```bash
cp backend/.env.docker.example backend/.env.docker          # edit; APP_KEY via: docker compose run --rm api php artisan key:generate --show
docker compose up --build                                   # dev stack on http://localhost:8080
docker compose exec api php artisan migrate --seed          # first run
```
Production: `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build` with `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `PUBLIC_URL` exported. Containers: `api` (php-fpm), `queue` (worker), `scheduler` (runs `schedule:run` every minute), `web` (Next.js), `nginx` (single origin: `/api` → Laravel, rest → Next), `db` (MySQL 8), `redis`.
- Put TLS in front (Caddy / Traefik / cloud load balancer). The bundled nginx speaks plain HTTP.
- `NEXT_PUBLIC_API_BASE_URL` is baked in at image **build** time — change it ⇒ rebuild `web`.
- Uploaded media and backups live in the `storage` volume. **Back this volume up too** (database backups are written there but an offsite copy is the real protection — set `BACKUP_OFFSITE_DISK` (see `config/backup.php`)).
- Sessions use the array driver (token API, no browser sessions); cache uses Redis in Docker, files on shared hosting.

## Health & monitoring
- `GET /up` — Laravel liveness (process is serving).
- `GET /api/v1/health` — readiness: database, cache, storage → 200 / 503, no secrets. With header `X-Health-Token: $HEALTH_TOKEN` it also returns queue backlog, oldest waiting job age, failed-job count and seconds since the scheduler last ran. Point an uptime monitor at it and alert when the status is not 200 **or** `scheduler_last_seen_seconds_ago` > 180 (cron stopped) **or** `failed_jobs` grows.
- Logs: set `LOG_CHANNEL=stderr` (Docker) or `daily` (shared hosting). No metrics exporter is built; the health endpoint is the hook.

## MySQL notes (from the static review — not yet run against a real server)
- `DB_CONNECTION=mysql` or `mariadb` are both handled (backup dump and audit triggers were fixed in Phase 18 to recognise `mariadb`).
- MySQL string comparison is case-insensitive (`utf8mb4_unicode_ci`); SQLite (used by the tests) is case-sensitive. Unique columns (codes, names) can therefore collide on MySQL where tests don't predict it.
- `ONLY_FULL_GROUP_BY` is on by default; every `groupBy` in the code was read and selects only grouped or aggregated columns.
- Money columns are `DECIMAL(12,2)`; UUID keys are `CHAR(36)`; Sanctum tokens use `uuidMorphs`.

## What was verified in Phase 18 — and what was not
Verified: backend suite (445 tests, SQLite), Pint clean, OpenAPI up to date, `docker compose config` accepts both compose files, `next build` with `output: "standalone"` produces `server.js`, workflow YAML parses.
**Not verified (no Docker daemon, no MySQL, no GitHub in the authoring sandbox):** building either image, running the compose stack, nginx config (`nginx -t`), the whole PHPUnit suite on MySQL, the GitHub Actions run itself, `composer audit` / `npm audit` results. The first CI run on GitHub is the real test; expect to fix a few things.


## Customer website (Phase 19)

- Deploy `website/` as its own Node service (`website/Dockerfile`, standalone, port 3001) on its own domain, e.g. `https://example.com`. Build args: `NEXT_PUBLIC_API_BASE_URL` (e.g. `https://admin.example.com/api/v1`) and `NEXT_PUBLIC_SITE_URL`. These are compiled into the browser bundle, so rebuild when they change.
- Backend `.env`: `WEBSITE_URL=https://example.com` and add the same origin to `CORS_ALLOWED_ORIGINS` (comma separated). Without CORS the browser blocks every call.
- Shared hosting without Node cannot run the website; use a Node host (VPS, Vercel, etc.) for `website/` while the API stays on PHP hosting.
- Turn on **Settings → Website → Website ordering** only after SMS (for login codes) and at least cash on delivery are configured and tested. The OTP SMS is sent immediately, not queued.
- Throttles: `web-public` 120/min per IP, `web-otp` 20/min per IP, `web-customer` 90/min, `web-order` 6/min. Behind a proxy make sure Laravel trusts it, otherwise every visitor shares one IP.

**Phase 19b notes.** Set `NEXT_PUBLIC_DEFAULT_LOCALE` (build arg) to the language bare URLs should open in. For live tracking use `REALTIME_DRIVER=pusher` (or Reverb) and make sure the website origin can reach the WebSocket host; with any other driver the site polls and nothing else is needed. The new migration `customer_addresses` must be run (`php artisan migrate`). Existing links like `/orders/{id}?payment=…` keep working through the language redirect.


## Rider app (Phase 20)

- Deploy `rider/` as its own Node service (`rider/Dockerfile`, port 3002) on **HTTPS**: GPS, the service worker and push do not work on plain HTTP (except localhost). Build arg `NEXT_PUBLIC_API_BASE_URL`; for push also `NEXT_PUBLIC_FIREBASE_API_KEY`, `_PROJECT_ID`, `_MESSAGING_SENDER_ID`, `_APP_ID`, `_VAPID_KEY` (all must be set, otherwise the app says alerts are not set up). Rebuild when they change.
- Add the rider origin to `CORS_ALLOWED_ORIGINS` in the backend `.env`.
- Run `php artisan migrate` and re-run `RolesAndPermissionsSeeder`. Create a staff user with the **Rider** role per rider, then link it in **Delivery → Riders**.
- Server-side push needs the FCM credentials already used for Phase 14 and a queue worker running; assignment alerts are queued, not instant without it.
- A manager settles collected cash from `POST /cash/rider-holdings/{rider}/settle` while their own shift is open.


## Staff app (Phase 21)

- Deploy `staff/` as its own Node service (`staff/Dockerfile`, port 3003) on **HTTPS** (service worker and push need it). Build arg `NEXT_PUBLIC_API_BASE_URL`; for push the same `NEXT_PUBLIC_FIREBASE_*` values as the Rider app (all five must be set). Add its origin to `CORS_ALLOWED_ORIGINS`.
- Re-run `RolesAndPermissionsSeeder` (new permission `staff.app`). Give each person a staff account with a role that has it and link the account in **HR → Employees**.
- Attendance uses the server's `APP_TIMEZONE`; wrong timezone means the "day" rolls over at the wrong hour.
- "Order ready" push goes through the notification queue: run the queue worker.


## Customer app (Phase 22)

- Deploy `customer-app/` as its own Node service (`customer-app/Dockerfile`, port 3004) on **HTTPS** (install-to-home-screen, service worker and push need it), e.g. `https://app.example.com`. Build arg `NEXT_PUBLIC_API_BASE_URL`; for push the same five `NEXT_PUBLIC_FIREBASE_*` values as the other apps (all must be set).
- Backend `.env`: add the app origin to `CORS_ALLOWED_ORIGINS`; for order alerts set `NOTIFY_ORDER_PUSH=true`, provide the FCM credentials used since Phase 14 and run the queue worker. Online payments still return to `WEBSITE_URL`.
- Customer sign-in sends an OTP SMS immediately: configure and test SMS first (same rule as the website).
