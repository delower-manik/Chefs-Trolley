# API Keys & Integration Instructions

Rule: **secrets live in `backend/.env` or in the admin console (Integrations → Payments, stored encrypted). Never commit them, never put them in a `NEXT_PUBLIC_*` variable** (those are public). Only the Firebase *web* config below is meant to be public.

## 1. SMS (customer login codes, order updates, campaigns)
Set `SMS_DRIVER` to one of `twilio | bulksmsbd | sslwireless | greenweb | alphasms | elitbuzz | none`, then fill that provider's keys:
| Driver | `.env` keys | Where to get them |
|---|---|---|
| Twilio | `TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_FROM` (or `TWILIO_MESSAGING_SERVICE_SID`) | Twilio Console → Account Info; buy/verify a sender |
| BulkSMSBD | `BULKSMSBD_API_KEY`, `BULKSMSBD_SENDER_ID` | bulksmsbd.net dashboard |
| SSL Wireless | `SSLWIRELESS_API_TOKEN`, `SSLWIRELESS_SID` | SSL Wireless SMS Plus panel |
| GreenWeb | `GREENWEB_TOKEN` | GreenWeb panel |
| AlphaSMS | `ALPHASMS_API_KEY` | sms.net.bd |
| ElitBuzz | `ELITBUZZ_API_KEY`, `ELITBUZZ_SENDER_ID` | ElitBuzz panel |
Also `NOTIFY_ORDER_SMS=true` to text customers on order events. BD operators require a registered sender ID/masking; use the provider's test balance first. Test from **Notifications → Send test**.

## 2. Push notifications (Firebase Cloud Messaging)
1. console.firebase.google.com → create a project → **Project settings → Cloud Messaging**; enable the FCM HTTP v1 API.
2. **Service account** (backend, secret): Project settings → Service accounts → *Generate new private key*. Set `FCM_PROJECT_ID=<project id>` and `FCM_CREDENTIALS=<path to the JSON file, or its contents>` in `backend/.env`. Keep the file outside the web root.
3. **Web app config** (public, per PWA): Project settings → *Your apps* → add a Web app → copy values into the build environment of `rider/`, `staff/`, `customer-app/`:
   `NEXT_PUBLIC_FIREBASE_API_KEY`, `NEXT_PUBLIC_FIREBASE_PROJECT_ID`, `NEXT_PUBLIC_FIREBASE_MESSAGING_SENDER_ID`, `NEXT_PUBLIC_FIREBASE_APP_ID`, `NEXT_PUBLIC_FIREBASE_VAPID_KEY` (Cloud Messaging → Web Push certificates → Generate key pair). All five must be set or the app reports alerts as not set up.
4. `NOTIFY_ORDER_PUSH=true` for customer order pushes. PWAs must be served over HTTPS; iOS needs the app added to the Home Screen.
For future native apps: add Android/iOS apps in the same Firebase project and use `POST /device-tokens` (staff/rider) / `POST /web/device-tokens` (customer).

## 3. Real-time (live order tracking, KDS refresh) — optional
`REALTIME_DRIVER=null` (polling, default) | `pusher` | `reverb` | `ably`.
- Pusher: `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, `PUSHER_APP_CLUSTER` (create a Channels app at pusher.com).
- Reverb/self-hosted Pusher protocol: same keys plus `PUSHER_HOST`, `PUSHER_PORT`, `PUSHER_SCHEME`.
- Ably: `ABLY_KEY` (`keyName:keySecret`, from ably.com; use a key with subscribe/publish capability only).

## 4. Payment gateways (online payment on the website)
Configured in the admin console: **Integrations → Payments → Configure**, per gateway: bKash, Nagad, SSLCommerz, AamarPay, EPS, PipraPay, Bangla QR (+ manual methods). Credentials are stored encrypted.
Requirements for all: `APP_URL` in `backend/.env` must be the **public HTTPS URL of the API** (providers call `APP_URL/api/v1/payment-callbacks/...`); `WEBSITE_URL` is where customers return. Register those callback/IPN URLs in the provider's merchant panel when asked. Start in the provider's **sandbox**, make a test payment, confirm the order shows paid exactly once, then switch to live credentials. Obtain live credentials from each provider's merchant onboarding (SSLCommerz store id/password, bKash app key/secret/username/password, etc.).

## 5. Webhooks (outgoing)
**Integrations → Webhooks**: give an HTTPS URL; each delivery is signed (HMAC header) and retried with back-off (1, 5, 30, 120, 360 min). Private/internal hosts are refused unless `WEBHOOKS_ALLOW_INSECURE=true` (local development only).

## 6. Backups
`BACKUP_ENCRYPTION_KEY` (32+ random chars; keep an offline copy: backups cannot be decrypted without it) and optional `BACKUP_OFFSITE_DISK` (see `config/backup.php`, e.g. an S3 disk).

## 7. Other
`HEALTH_TOKEN` (monitoring header), `TRUSTED_PROXIES` (behind a proxy), `CAMPAIGN_MAX_RECIPIENTS` (default 5000), `GATEWAY_SESSION_MINUTES` (default 30).
