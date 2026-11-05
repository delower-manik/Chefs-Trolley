# Chefs Trolley Restaurant OS — Final package

| # | Requested | Where | Status |
|---|---|---|---|
| 1 | Admin Panel (Laravel) | `backend/` API + `frontend/` admin console (+ `website/` public site) | Built and tested |
| 2 | Customer app (Android & iOS) | `customer-app/` (PWA) | PWA built. Native Flutter app NOT built |
| 3 | Staff app (Android, RBAC) | `staff/` (PWA) | PWA built. Native Flutter app NOT built |
| 4 | Rider app (Android) | `rider/` (PWA) | PWA built. Native Flutter app NOT built |
| 5 | POS app (Android POS terminal) | POS screen in `frontend/` (`/pos`, `/cash-register`) | Works in the admin console only; no separate installable POS app |

Native apps can be built against `backend/docs/openapi.json`; the PWAs are working reference clients.

## Read in this order
`docs/04_INSTALLATION_AND_INSTRUCTIONS.md` → `docs/05_API_KEYS_AND_INTEGRATIONS.md` → `docs/03_SERVER_REQUIREMENTS.md` → `docs/09_USER_MANUAL.md`
Reference: `docs/01_PROJECT_PROFILE.md`, `02_SYSTEM_REQUIREMENTS_AND_SPECIFICATION.md`, `06_PROJECT_STRUCTURE.md`, `07_DATA_SCHEMA.md`, `08_BLUEPRINT_ARCHITECTURE.md`, `10_SECURITY_TESTING_AND_READINESS.md`, `docs/DEPLOYMENT.md`, `README.md`.

**Not yet verified (needs real infrastructure):** MySQL, Docker/CI, real phones/GPS/push, printers, HTTPS, load, per-gateway sandbox payments. See `docs/10_…`.
