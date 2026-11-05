# Server Requirements

## Option A — Shared hosting (API only) + a Node host for the web apps
| Item | Requirement |
|---|---|
| PHP | 8.3 with extensions: ctype, curl, dom, fileinfo, json, mbstring, openssl, pdo_mysql, tokenizer, xml, zip, bcmath (gd or imagick optional) |
| DB | MySQL 8 or MariaDB 10.6+, utf8mb4, InnoDB, a user with CREATE/ALTER/INDEX/LOCK (TRIGGER only for `audit:protect`) |
| Cron | `* * * * * php artisan schedule:run` (drives queue, campaigns, reconciliation, backups). Without it nothing background runs |
| Queue | `database` driver is enough on shared hosting |
| Disk | 2 GB+ (media + encrypted backups grow); `php artisan storage:link` needed |
| Memory | 256 MB PHP memory_limit minimum |
| TLS | Required for production (HTTPS on every origin) |
| Node apps | `frontend/`, `website/`, `rider/`, `staff/`, `customer-app/` need Node 20+ at runtime (VPS, Vercel/Netlify, or Docker). Plain PHP shared hosting cannot run them |

## Option B — VPS / cloud with Docker (recommended for all 6 services)
| Size | Spec |
|---|---|
| Single restaurant | 2 vCPU, 4 GB RAM, 40 GB SSD |
| Multi-branch / busy | 4 vCPU, 8 GB RAM, 80 GB SSD, Redis, separate DB volume + offsite backups |
Containers (see `docker-compose.yml`, `docker-compose.prod.yml`): api (php-fpm), queue, scheduler, nginx, db (MySQL 8), redis, plus web apps. TLS must be terminated in front (Caddy/Traefik/LB).

## Domains (example)
`api.example.com` (Laravel `public/`), `admin.example.com`, `example.com` (website), `rider.example.com`, `staff.example.com`, `app.example.com` (customer PWA). All origins go into `CORS_ALLOWED_ORIGINS`.

## Production settings checklist
`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=<public API URL>` (used for payment callbacks), `TRUSTED_PROXIES=<proxy IP list>` when behind nginx/Cloudflare/LB (otherwise all users share one IP and rate limits break), `BACKUP_ENCRYPTION_KEY` (store a copy offline), `HEALTH_TOKEN`, `CORS_ALLOWED_ORIGINS`, `config:cache`, `route:cache`. Monitor `GET /api/v1/health`.
