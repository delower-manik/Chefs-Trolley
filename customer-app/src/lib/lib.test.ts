import { describe, expect, it } from "vitest";
import { lineKey, normalizePhone } from "./format";
import { nextDelay } from "./live-order";
import { translate } from "./i18n";

describe("customer app helpers", () => {
  it("normalises Bangladeshi phones, incl. Bangla digits", () => { expect(normalizePhone("+8801712345678")).toBe("01712345678"); expect(normalizePhone("০১৭১২৩৪৫৬৭৮")).toBe("01712345678"); expect(normalizePhone("12345")).toBeNull(); });
  it("same dish, options and note merge into one cart line", () => expect(lineKey("x", ["b", "a"], " Hi ")).toBe(lineKey("x", ["a", "b"], "hi")));
  it("tracking backs off on errors and relaxes when push is live", () => {
    expect(nextDelay({ pushLive: false, hidden: false, failures: 0, ageMs: 0 })).toBe(5000);
    expect(nextDelay({ pushLive: true, hidden: false, failures: 0, ageMs: 0 })).toBe(60000);
    expect(nextDelay({ pushLive: false, hidden: false, failures: 9, ageMs: 0 })).toBe(60000);
  });
  it("i18n has Bangla and falls back", () => { expect(translate("bn", "nav.menu")).toBe("মেনু"); expect(translate("en", "home.hello", { name: "A" })).toBe("Hello, A"); });
});
