import { api, ApiError } from "./client-api";
import { FIREBASE, pushConfigured } from "./config";

const KEY = "ct_customer_push_token";
export type PushState = "on" | "off" | "blocked" | "unavailable" | "unsupported";

export const pushSupported = () => typeof window !== "undefined" && "Notification" in window && "serviceWorker" in navigator && "PushManager" in window;
export const pushStatus = (): PushState => {
  if (!pushConfigured()) return "unavailable";
  if (!pushSupported()) return "unsupported";
  if (Notification.permission === "denied") return "blocked";
  return Notification.permission === "granted" && localStorage.getItem(KEY) ? "on" : "off";
};

/** Asks permission, subscribes through Firebase and registers the token with our server (which sends the actual pushes). */
export async function enablePush(onForeground?: () => void): Promise<PushState> {
  if (!pushConfigured()) return "unavailable";
  if (!pushSupported()) return "unsupported";
  if ((await Notification.requestPermission()) !== "granted") return "blocked";
  const reg = await navigator.serviceWorker.register("/sw.js");
  await navigator.serviceWorker.ready;
  const { initializeApp, getApps } = await import("@firebase/app");
  const { getMessaging, getToken, isSupported, onMessage } = await import("@firebase/messaging");
  if (!(await isSupported())) return "unsupported";
  const app = getApps()[0] ?? initializeApp({ apiKey: FIREBASE.apiKey, projectId: FIREBASE.projectId, messagingSenderId: FIREBASE.messagingSenderId, appId: FIREBASE.appId });
  const messaging = getMessaging(app);
  const token = await getToken(messaging, { vapidKey: FIREBASE.vapidKey, serviceWorkerRegistration: reg });
  if (!token) return "off";
  await api("/web/device-tokens", { body: { token, platform: "web" } });
  localStorage.setItem(KEY, token);
  if (onForeground) onMessage(messaging, () => onForeground()); // a push while the app is open just refreshes the list
  return "on";
}

/** On sign-out: stop pushing this user's deliveries to a phone someone else may pick up. Never blocks sign-out. */
export async function forgetPush(): Promise<void> {
  const token = localStorage.getItem(KEY);
  if (!token) return;
  try { await api("/web/device-tokens", { method: "DELETE", body: { token } }); } catch (e) { if (!(e instanceof ApiError)) return; }
  localStorage.removeItem(KEY);
}
