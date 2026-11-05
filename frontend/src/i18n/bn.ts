import type { MessageKey } from "./en";

// বাংলা অনুবাদ। কোনো কী না থাকলে ইংরেজি দেখায়। এটি নেটিভ স্পিকার দিয়ে একবার পর্যালোচনা করে নেওয়া ভালো।
export const bn: Partial<Record<MessageKey, string>> = {
  "lang.name": "বাংলা",
  "common.save": "সংরক্ষণ", "common.cancel": "বাতিল", "common.delete": "মুছুন", "common.edit": "সম্পাদনা", "common.close": "বন্ধ করুন",
  "common.loading": "লোড হচ্ছে…", "common.search": "খুঁজুন", "common.back": "পিছনে", "common.signOut": "সাইন আউট", "common.yes": "হ্যাঁ", "common.no": "না",
  "common.saved": "সংরক্ষিত হয়েছে।", "common.couldntSave": "সংরক্ষণ করা যায়নি। আবার চেষ্টা করুন।", "common.on": "চালু", "common.off": "বন্ধ",
  "header.live": "লাইভ", "header.connecting": "সংযোগ হচ্ছে", "header.offline": "অফলাইন", "header.menu": "মেনু", "header.theme": "থিম", "header.language": "ভাষা",
  "header.searchPlaceholder": "অর্ডার, আইটেম, গ্রাহক খুঁজুন...", "header.notifications": "নোটিফিকেশন", "header.mySecurity": "আমার নিরাপত্তা",
  "theme.system": "সিস্টেম", "theme.light": "হালকা", "theme.dark": "গাঢ়",

  "nav.group.Overview": "সারসংক্ষেপ", "nav.group.Restaurant Operations": "রেস্টুরেন্ট পরিচালনা", "nav.group.Menu": "মেনু", "nav.group.Inventory": "মজুদ",
  "nav.group.Delivery": "ডেলিভারি", "nav.group.Customers & Growth": "গ্রাহক ও প্রবৃদ্ধি", "nav.group.People & Finance": "কর্মী ও অর্থ",
  "nav.group.Digital Presence": "ডিজিটাল উপস্থিতি", "nav.group.Integrations": "ইন্টিগ্রেশন", "nav.group.Reports & Control": "রিপোর্ট ও নিয়ন্ত্রণ",
  "nav.dashboard": "ড্যাশবোর্ড", "nav.pos": "পিওএস", "nav.kitchen": "কিচেন / কেডিএস", "nav.orders": "অর্ডার", "nav.floor": "ফ্লোর প্ল্যান", "nav.tax": "করের হার",
  "nav.tables": "টেবিল", "nav.reservations": "টেবিল রিজার্ভেশন", "nav.waiters": "ওয়েটার অর্ডার", "nav.kitchens": "কিচেন স্টেশন", "nav.printers": "প্রিন্টার",
  "nav.cash": "ক্যাশ রেজিস্টার", "nav.categories": "ক্যাটাগরি", "nav.items": "খাবারের আইটেম", "nav.modifiers": "মডিফায়ার", "nav.recipes": "রেসিপি", "nav.combos": "কম্বো ও ডিল",
  "nav.ingredients": "উপকরণ", "nav.warehouses": "গুদাম", "nav.purchase": "ক্রয়", "nav.suppliers": "সরবরাহকারী", "nav.adjust": "স্টক সমন্বয়",
  "nav.transfers": "স্টক স্থানান্তর", "nav.delivery": "ডেলিভারি ব্যবস্থাপনা", "nav.drivers": "রাইডার", "nav.delivery_zones": "ডেলিভারি জোন",
  "nav.customers": "গ্রাহক", "nav.promotions": "প্রমোশন", "nav.reviews": "রিভিউ", "nav.gift-cards": "গিফট কার্ড", "nav.segments": "সেগমেন্ট", "nav.campaigns": "ক্যাম্পেইন",
  "nav.notifications": "নোটিফিকেশন", "nav.employees": "কর্মচারী", "nav.attendance": "উপস্থিতি", "nav.payroll": "বেতন", "nav.accounting": "হিসাব",
  "nav.cms": "ওয়েবসাইট সিএমএস", "nav.seo": "এসইও", "nav.media": "মিডিয়া লাইব্রেরি", "nav.theme": "থিম", "nav.payments": "পেমেন্ট গেটওয়ে", "nav.wifi": "ফ্রি ওয়াই-ফাই",
  "nav.branches": "শাখা", "nav.webhooks": "ওয়েবহুক", "nav.reports": "রিপোর্ট", "nav.users": "ব্যবহারকারী ও ভূমিকা", "nav.activity": "কার্যকলাপ লগ",
  "nav.backups": "ব্যাকআপ", "nav.security": "আমার নিরাপত্তা", "nav.settings": "সেটিংস",

  "login.subtitle": "রেস্টুরেন্ট ম্যানেজমেন্ট কনসোলে সাইন ইন করুন", "login.email": "ইমেইল", "login.password": "পাসওয়ার্ড", "login.signIn": "সাইন ইন", "login.signingIn": "সাইন ইন হচ্ছে...",
  "login.code": "অথেনটিকেটর কোড বা রিকভারি কোড", "login.verify": "যাচাই করুন",
  "login.codeHelp": "আপনার অথেনটিকেটর অ্যাপ খুলে ৬ অঙ্কের কোডটি দিন। ফোন হারিয়ে গেলে একটি রিকভারি কোড ব্যবহার করুন।",

  "dashboard.todaySales": "আজকের বিক্রি", "dashboard.orders": "আজকের অর্ডার", "dashboard.kitchen": "চলমান কিচেন অর্ডার", "dashboard.stock": "স্টক সতর্কতা",

  "settings.title": "সেটিংস", "settings.subtitle": "ব্যবসার তথ্য, ভাষা, রসিদ এবং কোন মডিউল চালু থাকবে।",
  "settings.business": "ব্যবসা", "settings.name": "ব্যবসার নাম", "settings.legalName": "আইনগত নাম", "settings.address": "ঠিকানা", "settings.phone": "ফোন",
  "settings.email": "ইমেইল", "settings.taxId": "বিআইএন / ভ্যাট নিবন্ধন নম্বর",
  "settings.regional": "ভাষা, সময় ও মুদ্রা", "settings.defaultLanguage": "ডিফল্ট ভাষা", "settings.timezone": "সময় অঞ্চল",
  "settings.currencyCode": "মুদ্রার কোড", "settings.currencySymbol": "মুদ্রার চিহ্ন", "settings.decimals": "স্ক্রিনে দশমিক সংখ্যা",
  "settings.currencyNote": "শুধু প্রদর্শনের জন্য — দাম সবসময় টাকায় (BDT) সংরক্ষিত ও হিসাব হয়। রসিদে সবসময় ২ দশমিক দেখায়।",
  "settings.receipts": "রসিদ", "settings.receiptLanguage": "রসিদের ভাষা", "settings.receiptHeader": "শিরোনাম লাইন", "settings.receiptFooter": "নিচের লেখা",
  "settings.showTaxId": "রসিদে বিআইএন / ভ্যাট নম্বর ছাপুন",
  "settings.thermalNote": "থার্মাল (ESC/POS) প্রিন্টার বাংলা অক্ষর ছাপতে পারে না — “?” আসে। ব্রাউজার/পিডিএফ প্রিন্টে বাংলা রসিদ ঠিক আছে।",
  "settings.website": "ওয়েবসাইট", "settings.web.ordering": "ওয়েবসাইটে অর্ডার", "settings.web.cod": "ক্যাশ অন ডেলিভারি / পিকআপে", "settings.web.pickup": "পিকআপ অর্ডার", "settings.web.min_order": "সর্বনিম্ন অর্ডারের পরিমাণ", "settings.websiteNote": "\"ওয়েবসাইটে অর্ডার\" চালু থাকলেই গ্রাহক পাবলিক ওয়েবসাইট থেকে অর্ডার করতে পারবেন। এটি আপনি চালু না করা পর্যন্ত বন্ধ থাকে। দাম, কর ও ছাড় সবসময় সার্ভার হিসাব করে।",
  "settings.features": "মডিউল", "settings.featuresNote": "মডিউল বন্ধ করলে মেনু থেকে লুকিয়ে যায় এবং সার্ভারও তার অনুরোধ নেয় না। আগের তথ্য থেকে যায়।",
  "settings.feature.gift_cards": "গিফট কার্ড", "settings.feature.campaigns": "সেগমেন্ট ও ক্যাম্পেইন", "settings.feature.reservations": "টেবিল রিজার্ভেশন", "settings.feature.wifi": "ফ্রি ওয়াই-ফাই জোন",
  "settings.appearance": "চেহারা (এই ডিভাইসে)",

  "security.title": "আমার নিরাপত্তা", "security.profile": "প্রোফাইল", "security.changePassword": "পাসওয়ার্ড পরিবর্তন", "security.twoStep": "দুই-ধাপে সাইন ইন",
  "security.sessions": "যেখানে সাইন ইন আছে", "backups.title": "ব্যাকআপ", "backups.now": "এখনই ব্যাকআপ নিন",
};
