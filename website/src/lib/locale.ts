export const LOCALES = ["en", "bn"] as const;
export type Locale = (typeof LOCALES)[number];
export const isLocale = (v: unknown): v is Locale => v === "en" || v === "bn";
/** Used only to send a bare URL (e.g. "/menu", a payment return) to a language; baked in at build time. */
export const DEFAULT_LOCALE: Locale = isLocale(process.env.NEXT_PUBLIC_DEFAULT_LOCALE) ? (process.env.NEXT_PUBLIC_DEFAULT_LOCALE as Locale) : "en";
export const LOCALE_COOKIE = "ct_locale";

/** "/menu" + "bn" -> "/bn/menu"; "/" -> "/bn". Query strings pass through untouched. */
export function localePath(lang: Locale, path: string): string {
  const p = path.startsWith("/") ? path : `/${path}`;
  return p === "/" ? `/${lang}` : `/${lang}${p}`;
}
/** Swap the language segment of a current path: "/en/menu?b=1" -> "/bn/menu?b=1". */
export function switchLocalePath(pathname: string, to: Locale): string {
  const m = pathname.match(/^\/(en|bn)(\/.*)?$/);
  return localePath(to, m ? m[2] ?? "/" : pathname);
}
export const otherLocale = (l: Locale): Locale => (l === "en" ? "bn" : "en");
