import { NextResponse, type NextRequest } from "next/server";
import { DEFAULT_LOCALE, LOCALE_COOKIE, isLocale } from "@/lib/locale";

/** Every page lives under /en or /bn (so each language has its own URL for hreflang). Bare URLs are redirected. */
export function middleware(req: NextRequest) {
  const { pathname, search } = req.nextUrl;
  if (/^\/(en|bn)(\/|$)/.test(pathname)) return NextResponse.next();
  const cookie = req.cookies.get(LOCALE_COOKIE)?.value;
  const accept = (req.headers.get("accept-language") ?? "").toLowerCase();
  const lang = isLocale(cookie) ? cookie : accept.startsWith("bn") ? "bn" : DEFAULT_LOCALE;
  const url = req.nextUrl.clone();
  url.pathname = pathname === "/" ? `/${lang}` : `/${lang}${pathname}`;
  url.search = search;
  return NextResponse.redirect(url, 308);
}

export const config = { matcher: ["/((?!_next/|api/|.*\\..*).*)"] }; // skip assets, files with an extension (robots.txt, sitemap.xml, images)
