# Chefs Trolley Staff app (PWA)

Next.js 14, port 3003: waiter tables and orders, kitchen display, attendance. Sign in with a staff account linked to an employee (HR → Employees).

```
cp .env.example .env.local   # NEXT_PUBLIC_API_BASE_URL (+ Firebase values for push)
npm install && npm run dev
npm run typecheck && npm test && npm run build
```

Needs HTTPS in production. See the repository README ("Phase 21") and docs/DEPLOYMENT.md.
