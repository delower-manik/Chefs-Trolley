export type Lang = "en" | "bn";
const en = {
  "app.name": "Staff", "login.title": "Staff sign in", "login.email": "Email", "login.password": "Password", "login.submit": "Sign in", "login.code": "Verification code", "login.notStaff": "This account has no staff app access or is not linked to an employee profile. Ask your manager.",
  "tab.tables": "Tables", "tab.kitchen": "Kitchen", "tab.me": "Me", "signout": "Sign out", "loading": "Loading…", "back": "Back", "refresh": "Refresh",
  "err.network": "No connection. Check your signal and try again.", "err.generic": "Something went wrong. Try again.", "offline": "You are offline. Orders and updates need a connection.",
  "w.ready": "Ready to serve", "w.served": "Served", "w.nothingReady": "Nothing is ready right now.", "w.free": "Free", "w.open": "Open order", "w.seats": "{n} seats",
  "t.menu": "Menu", "t.current": "On the table", "t.cart": "New items", "t.send": "Send to kitchen", "t.empty": "Add items from the menu.", "t.add": "Add", "t.note": "Note for the kitchen (optional)", "t.estimate": "Estimated total (final price is set by the server)", "t.chooseOptions": "Choose the required options", "t.sent": "Sent to the kitchen.", "t.noOrder": "No items yet.", "t.total": "Order total", "t.qty": "Qty",
  "k.all": "All stations", "k.empty": "No tickets. All caught up.", "k.next.new": "Accept", "k.next.accepted": "Start", "k.next.preparing": "Ready", "k.undo": "Undo", "k.min": "{n} min", "k.table": "Table {t}", "k.state.new": "New", "k.state.accepted": "Accepted", "k.state.preparing": "Preparing", "k.state.ready": "Ready",
  "a.title": "Attendance", "a.in": "Clock in", "a.out": "Clock out", "a.status.in": "You are clocked in", "a.status.out": "Not clocked in", "a.done": "Finished for today", "a.since": "Since {t}", "a.history": "Last 14 days", "a.note": "Times are recorded by the server, not your phone.",
  "push.enable": "Turn on alerts", "push.on": "Alerts are on", "push.blocked": "Notifications are blocked in your browser settings.", "push.unavailable": "Alerts are not set up on this server.",
} as const;
export type Key = keyof typeof en;
const bn: Partial<Record<Key, string>> = {
  "app.name": "স্টাফ", "login.title": "স্টাফ সাইন ইন", "login.email": "ইমেইল", "login.password": "পাসওয়ার্ড", "login.submit": "সাইন ইন", "login.code": "ভেরিফিকেশন কোড", "login.notStaff": "এই অ্যাকাউন্টের স্টাফ অ্যাপের অনুমতি নেই বা কর্মী প্রোফাইলের সাথে যুক্ত নয়। ম্যানেজারকে জানান।",
  "tab.tables": "টেবিল", "tab.kitchen": "কিচেন", "tab.me": "আমি", "signout": "সাইন আউট", "loading": "লোড হচ্ছে…", "back": "পিছনে", "refresh": "রিফ্রেশ",
  "err.network": "সংযোগ নেই। নেটওয়ার্ক দেখে আবার চেষ্টা করুন।", "err.generic": "কিছু একটা ভুল হয়েছে। আবার চেষ্টা করুন।", "offline": "আপনি অফলাইনে। অর্ডার ও আপডেটের জন্য সংযোগ লাগবে।",
  "w.ready": "পরিবেশনের জন্য তৈরি", "w.served": "পরিবেশন হয়েছে", "w.nothingReady": "এখন কিছু তৈরি নেই।", "w.free": "খালি", "w.open": "খোলা অর্ডার", "w.seats": "{n} সিট",
  "t.menu": "মেনু", "t.current": "টেবিলে আছে", "t.cart": "নতুন আইটেম", "t.send": "কিচেনে পাঠান", "t.empty": "মেনু থেকে আইটেম যোগ করুন।", "t.add": "যোগ", "t.note": "কিচেনের জন্য নোট (ঐচ্ছিক)", "t.estimate": "আনুমানিক মোট (চূড়ান্ত দাম সার্ভার ঠিক করে)", "t.chooseOptions": "আবশ্যক অপশন বেছে নিন", "t.sent": "কিচেনে পাঠানো হয়েছে।", "t.noOrder": "এখনো কোনো আইটেম নেই।", "t.total": "অর্ডারের মোট", "t.qty": "পরিমাণ",
  "k.all": "সব স্টেশন", "k.empty": "কোনো টিকিট নেই। সব শেষ।", "k.next.new": "গ্রহণ", "k.next.accepted": "শুরু", "k.next.preparing": "তৈরি", "k.undo": "ফিরিয়ে নিন", "k.min": "{n} মিনিট", "k.table": "টেবিল {t}", "k.state.new": "নতুন", "k.state.accepted": "গৃহীত", "k.state.preparing": "রান্না হচ্ছে", "k.state.ready": "তৈরি",
  "a.title": "হাজিরা", "a.in": "ক্লক ইন", "a.out": "ক্লক আউট", "a.status.in": "আপনি ক্লক ইন করেছেন", "a.status.out": "ক্লক ইন করা হয়নি", "a.done": "আজকের কাজ শেষ", "a.since": "{t} থেকে", "a.history": "গত ১৪ দিন", "a.note": "সময় সার্ভার রেকর্ড করে, আপনার ফোন নয়।",
  "push.enable": "অ্যালার্ট চালু করুন", "push.on": "অ্যালার্ট চালু আছে", "push.blocked": "ব্রাউজার সেটিংসে নোটিফিকেশন বন্ধ আছে।", "push.unavailable": "এই সার্ভারে অ্যালার্ট সেট করা নেই।",
};
export function translate(lang: Lang, k: Key, vars?: Record<string, string | number>): string {
  let s: string = (lang === "bn" ? bn[k] : undefined) ?? en[k] ?? k;
  if (vars) for (const [a, b] of Object.entries(vars)) s = s.replaceAll(`{${a}}`, String(b));
  return s;
}
