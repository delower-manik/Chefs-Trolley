/* Chefs Trolley staff: push notifications ONLY. Deliberately no caching: a stale ticket or table state is worse than none. */
self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", (e) => e.waitUntil(self.clients.claim()));

self.addEventListener("push", (event) => {
  let p = {};
  try { p = event.data ? event.data.json() : {}; } catch { /* not JSON */ }
  const n = p.notification || {};
  const title = n.title || "Chefs Trolley";
  event.waitUntil(self.registration.showNotification(title, { body: n.body || "", icon: "/icon-192.png", badge: "/icon-192.png", tag: "order", renotify: true, data: { url: "/" } }));
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  event.waitUntil(self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((list) => {
    for (const c of list) if ("focus" in c) return c.focus();
    return self.clients.openWindow("/");
  }));
});
