# Chefs Trolley Customer app (PWA)

Next.js 14, port 3004: menu, order, live tracking, loyalty, order-again, reservations, push. Same phone + OTP account as the website.

```
cp .env.example .env.local   # NEXT_PUBLIC_API_BASE_URL (+ Firebase values for push)
npm install && npm run dev
npm run typecheck && npm test && npm run build
```

Needs HTTPS in production. See the repository README ("Phase 22") and docs/DEPLOYMENT.md.
