// Baseline browser hardening. A strict Content-Security-Policy is NOT set here on purpose: the app loads Google Fonts /
// Material Symbols and Next injects inline scripts, so a CSP needs a nonce setup and testing in a real browser first.
const securityHeaders = [
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "X-Frame-Options", value: "DENY" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=(), payment=()" },
  { key: "Strict-Transport-Security", value: "max-age=31536000; includeSubDomains" },
];

/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  poweredByHeader: false,
  output: "standalone", // smaller Docker image (see frontend/Dockerfile)
  experimental: {
    typedRoutes: true,
  },
  async headers() {
    return [{ source: "/:path*", headers: securityHeaders }];
  },
};

export default nextConfig;
