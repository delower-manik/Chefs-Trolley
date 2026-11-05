export type Lang = "en" | "bn";
const en = {
  "nav.home": "Home", "nav.menu": "Menu", "nav.orders": "Orders", "nav.account": "Account", "loading": "Loading…", "back": "Back", "close": "Close",
  "err.network": "Can't reach the restaurant. Check your connection and try again.", "offline": "You are offline. Ordering needs a connection.",
  "home.hello": "Hello, {name}", "home.welcome": "Welcome", "home.closed": "Online ordering is paused right now.", "home.order": "Order now", "home.active": "Your order", "home.track": "Track", "home.signin": "Sign in to order and track", "home.points": "{n} points", "home.again": "Order again",
  "login.title": "Sign in with your phone", "login.phone": "Mobile number (01XXXXXXXXX)", "login.send": "Send code", "login.code": "6-digit code", "login.verify": "Verify", "login.name": "Your name", "login.badPhone": "Enter a valid Bangladeshi mobile number, like 01712345678.", "login.resend": "Use a different number",
  "menu.add": "Add", "menu.options": "Choose options", "menu.note": "Note (optional)", "menu.combos": "Combos", "menu.required": "Required", "menu.chooseRequired": "Choose the required options", "menu.viewCart": "View cart", "menu.items": "{n} items", "menu.switched": "Your cart was from another branch and was cleared.",
  "cart.title": "Your order", "cart.empty": "Your cart is empty.", "cart.remove": "Remove",
  "co.delivery": "Delivery", "co.pickup": "Pickup", "co.zone": "Delivery area", "co.address": "Address", "co.saved": "Saved address", "co.phone": "Phone", "co.promo": "Promo code (optional)", "co.pay": "Payment", "co.cod": "Cash on delivery / pickup", "co.place": "Place order", "co.subtotal": "Subtotal", "co.fee": "Delivery fee", "co.total": "Estimated total", "co.minOrder": "Minimum order is {amount}.", "co.estimate": "The final price, tax and discounts are set by the restaurant.", "co.signin": "Sign in to place your order.",
  "o.empty": "No orders yet.", "o.cancel": "Cancel order", "o.pay": "Pay now", "o.again": "Order again", "o.review": "Rate this order", "o.send": "Send", "o.thanks": "Thanks for your feedback.", "o.rider": "Rider: {name}", "o.skipped": "Some items are no longer available: {names}", "o.total": "Total", "o.paid": "Paid", "o.due": "Due", "o.live": "Live updates",
  "stage.received": "Order received", "stage.preparing": "Preparing", "stage.ready": "Ready", "stage.on_the_way": "On the way", "stage.done": "Done", "stage.cancelled": "Cancelled",
  "a.title": "Account", "a.points": "Loyalty points", "a.history": "Recent activity", "a.name": "Name", "a.email": "Email", "a.save": "Save", "a.saved": "Saved.", "a.addresses": "Saved addresses", "a.label": "Label (Home, Work)", "a.addAddress": "Add address", "a.default": "Default", "a.makeDefault": "Make default", "a.remove": "Remove", "a.signout": "Sign out", "a.language": "Language", "a.reservations": "Table reservations",
  "r.title": "Book a table", "r.branch": "Branch", "r.party": "Guests", "r.when": "Date and time", "r.notes": "Notes (optional)", "r.book": "Book", "r.mine": "Your reservations", "r.cancel": "Cancel", "r.none": "No reservations.", "r.booked": "Requested. The restaurant will confirm.",
  "push.enable": "Turn on order alerts", "push.on": "Order alerts are on", "push.blocked": "Notifications are blocked in your browser settings.", "push.unavailable": "Alerts are not set up on this server.",
  "pay.paid": "Payment received.", "pay.failed": "Payment did not go through.", "pay.pending": "Payment is pending.",
} as const;
export type Key = keyof typeof en;
const bn: Partial<Record<Key, string>> = {
  "nav.home": "হোম", "nav.menu": "মেনু", "nav.orders": "অর্ডার", "nav.account": "অ্যাকাউন্ট", "loading": "লোড হচ্ছে…", "back": "পিছনে", "close": "বন্ধ",
  "err.network": "রেস্টুরেন্টের সাথে সংযোগ হচ্ছে না। নেটওয়ার্ক দেখে আবার চেষ্টা করুন।", "offline": "আপনি অফলাইনে। অর্ডারের জন্য সংযোগ লাগবে।",
  "home.hello": "হ্যালো, {name}", "home.welcome": "স্বাগতম", "home.closed": "অনলাইন অর্ডার এখন বন্ধ আছে।", "home.order": "এখনই অর্ডার করুন", "home.active": "আপনার অর্ডার", "home.track": "ট্র্যাক করুন", "home.signin": "অর্ডার ও ট্র্যাক করতে সাইন ইন করুন", "home.points": "{n} পয়েন্ট", "home.again": "আবার অর্ডার করুন",
  "login.title": "ফোন নম্বর দিয়ে সাইন ইন", "login.phone": "মোবাইল নম্বর (01XXXXXXXXX)", "login.send": "কোড পাঠান", "login.code": "৬ সংখ্যার কোড", "login.verify": "যাচাই করুন", "login.name": "আপনার নাম", "login.badPhone": "সঠিক বাংলাদেশি মোবাইল নম্বর দিন, যেমন 01712345678।", "login.resend": "অন্য নম্বর ব্যবহার করুন",
  "menu.add": "যোগ", "menu.options": "অপশন বেছে নিন", "menu.note": "নোট (ঐচ্ছিক)", "menu.combos": "কম্বো", "menu.required": "আবশ্যক", "menu.chooseRequired": "আবশ্যক অপশন বেছে নিন", "menu.viewCart": "কার্ট দেখুন", "menu.items": "{n} আইটেম", "menu.switched": "আপনার কার্ট অন্য শাখার ছিল, তাই খালি করা হয়েছে।",
  "cart.title": "আপনার অর্ডার", "cart.empty": "আপনার কার্ট খালি।", "cart.remove": "সরান",
  "co.delivery": "ডেলিভারি", "co.pickup": "পিকআপ", "co.zone": "ডেলিভারি এলাকা", "co.address": "ঠিকানা", "co.saved": "সংরক্ষিত ঠিকানা", "co.phone": "ফোন", "co.promo": "প্রোমো কোড (ঐচ্ছিক)", "co.pay": "পেমেন্ট", "co.cod": "ক্যাশ অন ডেলিভারি / পিকআপ", "co.place": "অর্ডার করুন", "co.subtotal": "সাবটোটাল", "co.fee": "ডেলিভারি ফি", "co.total": "আনুমানিক মোট", "co.minOrder": "সর্বনিম্ন অর্ডার {amount}।", "co.estimate": "চূড়ান্ত দাম, ট্যাক্স ও ডিসকাউন্ট রেস্টুরেন্ট ঠিক করে।", "co.signin": "অর্ডার করতে সাইন ইন করুন।",
  "o.empty": "এখনো কোনো অর্ডার নেই।", "o.cancel": "অর্ডার বাতিল", "o.pay": "এখনই পরিশোধ", "o.again": "আবার অর্ডার", "o.review": "অর্ডারটি রেট করুন", "o.send": "পাঠান", "o.thanks": "মতামতের জন্য ধন্যবাদ।", "o.rider": "রাইডার: {name}", "o.skipped": "কিছু আইটেম এখন পাওয়া যাচ্ছে না: {names}", "o.total": "মোট", "o.paid": "পরিশোধিত", "o.due": "বাকি", "o.live": "লাইভ আপডেট",
  "stage.received": "অর্ডার গৃহীত", "stage.preparing": "তৈরি হচ্ছে", "stage.ready": "তৈরি", "stage.on_the_way": "পথে আছে", "stage.done": "সম্পন্ন", "stage.cancelled": "বাতিল",
  "a.title": "অ্যাকাউন্ট", "a.points": "লয়্যালটি পয়েন্ট", "a.history": "সাম্প্রতিক কার্যকলাপ", "a.name": "নাম", "a.email": "ইমেইল", "a.save": "সংরক্ষণ", "a.saved": "সংরক্ষিত।", "a.addresses": "সংরক্ষিত ঠিকানা", "a.label": "লেবেল (বাসা, অফিস)", "a.addAddress": "ঠিকানা যোগ", "a.default": "ডিফল্ট", "a.makeDefault": "ডিফল্ট করুন", "a.remove": "সরান", "a.signout": "সাইন আউট", "a.language": "ভাষা", "a.reservations": "টেবিল রিজার্ভেশন",
  "r.title": "টেবিল বুক করুন", "r.branch": "শাখা", "r.party": "অতিথি", "r.when": "তারিখ ও সময়", "r.notes": "নোট (ঐচ্ছিক)", "r.book": "বুক করুন", "r.mine": "আপনার রিজার্ভেশন", "r.cancel": "বাতিল", "r.none": "কোনো রিজার্ভেশন নেই।", "r.booked": "অনুরোধ পাঠানো হয়েছে। রেস্টুরেন্ট নিশ্চিত করবে।",
  "push.enable": "অর্ডারের অ্যালার্ট চালু করুন", "push.on": "অর্ডারের অ্যালার্ট চালু আছে", "push.blocked": "ব্রাউজার সেটিংসে নোটিফিকেশন বন্ধ আছে।", "push.unavailable": "এই সার্ভারে অ্যালার্ট সেট করা নেই।",
  "pay.paid": "পেমেন্ট পাওয়া গেছে।", "pay.failed": "পেমেন্ট হয়নি।", "pay.pending": "পেমেন্ট অপেক্ষমাণ।",
};
export function translate(lang: Lang, k: Key, vars?: Record<string, string | number>): string {
  let s: string = (lang === "bn" ? bn[k] : undefined) ?? en[k] ?? k;
  if (vars) for (const [a, b] of Object.entries(vars)) s = s.replaceAll(`{${a}}`, String(b));
  return s;
}
