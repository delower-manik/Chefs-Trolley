"use client";

import { useEffect, useState } from "react";
import { useApp } from "./providers";

const KEY = "ct_web_notice";

/**
 * This site stores only what it needs to work: the sign-in token, the cart and the language choice. There are no
 * analytics, advertising or tracking cookies, so there is nothing to consent to; this is an information notice.
 * If tracking is ever added, replace this with a real consent manager BEFORE loading any tracker.
 */
export default function CookieNotice() {
  const { t } = useApp();
  const [show, setShow] = useState(false);
  useEffect(() => { try { setShow(window.localStorage.getItem(KEY) !== "1"); } catch { setShow(false); } }, []);
  if (!show) return null;
  return (
    <div role="region" aria-label="Notice" className="fixed inset-x-3 bottom-3 z-40 mx-auto flex max-w-xl items-center gap-3 rounded-2xl border border-line bg-white p-3 text-sm shadow-lg">
      <p className="flex-1 text-soft">{t("notice.text")}</p>
      <button type="button" className="btn !min-h-[40px] !px-4" onClick={() => { try { window.localStorage.setItem(KEY, "1"); } catch { /* ignore */ } setShow(false); }}>{t("notice.ok")}</button>
    </div>
  );
}
