import type { Metadata } from "next";
import { DEFAULT_LOCALE, LOCALES, localePath, type Locale } from "./locale";

/** canonical + hreflang for a page that exists in every language. `path` is the language-less path ("/menu", "/p/about", "/"). */
export function alternates(lang: Locale, path: string): NonNullable<Metadata["alternates"]> {
  return {
    canonical: localePath(lang, path),
    languages: { ...Object.fromEntries(LOCALES.map((l) => [l, localePath(l, path)])), "x-default": localePath(DEFAULT_LOCALE, path) },
  };
}

/** schema.org JSON-LD -> a string safe to put in <script type="application/ld+json"> ("<" can never close the tag). */
export const jsonLd = (data: unknown): string => JSON.stringify(data).replace(/</g, "\\u003c");
