# Chefs Trolley Rider app (PWA)

Next.js 14, port 3002. Sign in with a staff account that has the Rider role; the admin links it to a rider record.

```
cp .env.example .env.local   # set NEXT_PUBLIC_API_BASE_URL (+ Firebase values for push)
npm install && npm run dev
npm run typecheck && npm test && npm run build
```

Needs HTTPS in production. See the repository README ("Phase 20") and docs/DEPLOYMENT.md for behaviour, limits and what has not been tested on a real phone.
