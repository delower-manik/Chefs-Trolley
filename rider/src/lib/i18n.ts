export type Lang = "en" | "bn";
const en = {
  "app.name": "Rider", "login.title": "Rider sign in", "login.email": "Email", "login.password": "Password", "login.submit": "Sign in", "login.code": "Verification code", "login.notRider": "This account is not set up as a rider. Ask your manager.",
  "tab.active": "Deliveries", "tab.history": "History", "tab.cash": "Cash", "signout": "Sign out",
  "status.available": "Available", "status.busy": "On a delivery", "status.offline": "Offline", "status.goOnline": "Go online", "status.goOffline": "Go offline",
  "list.empty": "No deliveries right now.", "list.emptyHistory": "No finished deliveries yet.", "list.refresh": "Refresh", "list.cancelledNote": "Cancelled by the restaurant",
  "d.address": "Address", "d.navigate": "Open in Maps", "d.call": "Call customer", "d.items": "Items", "d.collect": "Collect cash", "d.paid": "Already paid. Do not collect cash.",
  "d.pickup": "I picked it up", "d.deliver": "Delivered", "d.fail": "Could not deliver", "d.reason": "What happened?", "d.confirmCash": "I collected exactly {amount}", "d.recipient": "Received by (optional)",
  "d.sure": "Confirm", "d.back": "Back", "d.delivered": "Delivered", "d.failed": "Not delivered",
  "cash.holding": "You are holding", "cash.note": "Hand this cash to your manager. They will mark it received.", "cash.none": "You are not holding any cash.",
  "loc.title": "Share my live location", "loc.note": "Only while you carry an order. Stops by itself when you finish. Keep this screen open: phones pause location in the background.", "loc.denied": "Location permission was denied. Allow it in your browser settings.", "loc.unsupported": "This phone's browser can't share location.", "loc.on": "Sharing",
  "push.enable": "Turn on delivery alerts", "push.on": "Alerts are on", "push.blocked": "Notifications are blocked in your browser settings.", "push.unavailable": "Alerts are not set up on this server.",
  "err.network": "No connection. Check your signal and try again.", "err.generic": "Something went wrong. Try again.", "offline": "You are offline. Actions need a connection.", "install": "Tip: add this app to your home screen from the browser menu.", "loading": "Loading…",
  "min.ago": "{n} min ago", "total": "Order total",
} as const;
export type Key = keyof typeof en;
const bn: Partial<Record<Key, string>> = {
  "app.name": "রাইডার", "login.title": "রাইডার সাইন ইন", "login.email": "ইমেইল", "login.password": "পাসওয়ার্ড", "login.submit": "সাইন ইন", "login.code": "ভেরিফিকেশন কোড", "login.notRider": "এই অ্যাকাউন্ট রাইডার হিসেবে সেট করা নেই। ম্যানেজারকে জানান।",
  "tab.active": "ডেলিভারি", "tab.history": "ইতিহাস", "tab.cash": "ক্যাশ", "signout": "সাইন আউট",
  "status.available": "প্রস্তুত", "status.busy": "ডেলিভারিতে", "status.offline": "অফলাইন", "status.goOnline": "অনলাইন হোন", "status.goOffline": "অফলাইন হোন",
  "list.empty": "এখন কোনো ডেলিভারি নেই।", "list.emptyHistory": "এখনো কোনো শেষ হওয়া ডেলিভারি নেই।", "list.refresh": "রিফ্রেশ", "list.cancelledNote": "রেস্টুরেন্ট বাতিল করেছে",
  "d.address": "ঠিকানা", "d.navigate": "ম্যাপে খুলুন", "d.call": "কাস্টমারকে কল", "d.items": "আইটেম", "d.collect": "ক্যাশ নিন", "d.paid": "আগেই পরিশোধিত। ক্যাশ নেবেন না।",
  "d.pickup": "আমি নিয়েছি", "d.deliver": "ডেলিভারি হয়েছে", "d.fail": "ডেলিভারি করা যায়নি", "d.reason": "কী হয়েছে?", "d.confirmCash": "আমি ঠিক {amount} নিয়েছি", "d.recipient": "যিনি নিলেন (ঐচ্ছিক)",
  "d.sure": "নিশ্চিত", "d.back": "পিছনে", "d.delivered": "ডেলিভারি হয়েছে", "d.failed": "ডেলিভারি হয়নি",
  "cash.holding": "আপনার কাছে আছে", "cash.note": "এই ক্যাশ ম্যানেজারকে বুঝিয়ে দিন। তিনি জমা হিসেবে চিহ্নিত করবেন।", "cash.none": "আপনার কাছে কোনো ক্যাশ নেই।",
  "loc.title": "আমার লাইভ লোকেশন শেয়ার করুন", "loc.note": "শুধু অর্ডার বহনের সময়। শেষ হলে নিজে থেকে বন্ধ হয়। এই স্ক্রিন খোলা রাখুন: ফোন ব্যাকগ্রাউন্ডে লোকেশন থামিয়ে দেয়।", "loc.denied": "লোকেশনের অনুমতি দেওয়া হয়নি। ব্রাউজার সেটিংসে অনুমতি দিন।", "loc.unsupported": "এই ফোনের ব্রাউজার লোকেশন শেয়ার করতে পারে না।", "loc.on": "শেয়ার হচ্ছে",
  "push.enable": "ডেলিভারির অ্যালার্ট চালু করুন", "push.on": "অ্যালার্ট চালু আছে", "push.blocked": "ব্রাউজার সেটিংসে নোটিফিকেশন বন্ধ আছে।", "push.unavailable": "এই সার্ভারে অ্যালার্ট সেট করা নেই।",
  "err.network": "সংযোগ নেই। নেটওয়ার্ক দেখে আবার চেষ্টা করুন।", "err.generic": "কিছু একটা ভুল হয়েছে। আবার চেষ্টা করুন।", "offline": "আপনি অফলাইনে। কাজ করতে সংযোগ লাগবে।", "install": "টিপ: ব্রাউজার মেনু থেকে অ্যাপটি হোম স্ক্রিনে যোগ করুন।", "loading": "লোড হচ্ছে…",
  "min.ago": "{n} মিনিট আগে", "total": "অর্ডারের মোট",
};
export function translate(lang: Lang, k: Key, vars?: Record<string, string | number>): string {
  let s: string = (lang === "bn" ? bn[k] : undefined) ?? en[k] ?? k;
  if (vars) for (const [a, b] of Object.entries(vars)) s = s.replaceAll(`{${a}}`, String(b));
  return s;
}
