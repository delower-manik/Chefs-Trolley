// Ably's browser bundle breaks under Next's SWC loader, so it is not bundled: this copies the vendor build into
// public/vendor and the app loads it with a script tag, only when Ably is the configured real-time provider.
import { copyFileSync, existsSync, mkdirSync } from "node:fs";

const src = "node_modules/ably/build/ably.min.js";
if (!existsSync(src)) {
  console.warn("[copy-ably] ably is not installed; skipping.");
  process.exit(0);
}
mkdirSync("public/vendor", { recursive: true });
copyFileSync(src, "public/vendor/ably.min.js");
