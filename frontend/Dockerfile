# syntax=docker/dockerfile:1
# Chefs Trolley admin console (Next.js 14, standalone output). Context: ./frontend
# NEXT_PUBLIC_* values are baked in at BUILD time — pass NEXT_PUBLIC_API_BASE_URL as a build arg.
# NOT built in the authoring sandbox (no Docker daemon there).

FROM node:20-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json* ./
COPY scripts ./scripts
RUN if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; else npm install --no-audit --no-fund; fi

FROM node:20-alpine AS build
WORKDIR /app
ARG NEXT_PUBLIC_API_BASE_URL
ENV NEXT_PUBLIC_API_BASE_URL=$NEXT_PUBLIC_API_BASE_URL NEXT_TELEMETRY_DISABLED=1
COPY --from=deps /app/node_modules ./node_modules
COPY . .
# public/vendor is generated (not committed); the deps stage's copy does not carry over.
RUN mkdir -p public && node scripts/copy-ably.mjs
# The layout loads Google Fonts at build time, so this build needs internet access.
RUN npm run build

FROM node:20-alpine AS run
WORKDIR /app
ENV NODE_ENV=production NEXT_TELEMETRY_DISABLED=1 PORT=3000 HOSTNAME=0.0.0.0
RUN addgroup -S app && adduser -S app -G app
COPY --from=build --chown=app:app /app/.next/standalone ./
COPY --from=build --chown=app:app /app/.next/static ./.next/static
COPY --from=build --chown=app:app /app/public ./public
USER app
EXPOSE 3000
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD wget -qO- http://127.0.0.1:3000/login >/dev/null || exit 1
CMD ["node", "server.js"]
