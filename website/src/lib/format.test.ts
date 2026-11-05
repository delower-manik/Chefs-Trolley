import { describe, expect, it } from "vitest";
import { formatMoney, hexToRgbChannels, lineKey, normalizePhone, safeColor } from "./format";
import { translate } from "@/components/providers";

describe("normalizePhone", () => {
  it("accepts the three Bangladeshi spellings and Bangla digits", () => {
    expect(normalizePhone("01712345678")).toBe("01712345678");
    expect(normalizePhone("+880 1712-345678")).toBe("01712345678");
    expect(normalizePhone("8801712345678")).toBe("01712345678");
    expect(normalizePhone("০১৭১২৩৪৫৬৭৮")).toBe("01712345678");
  });
  it("rejects anything else", () => {
    expect(normalizePhone("0121234567")).toBeNull();
    expect(normalizePhone("abc")).toBeNull();
    expect(normalizePhone("017123456789")).toBeNull();
  });
});

describe("safeColor", () => {
  it("lets only #RRGGBB reach CSS", () => {
    expect(safeColor("#c2410c")).toBe("#c2410c");
    expect(safeColor("red; background:url(x)")).toBeNull();
    expect(safeColor("#fff")).toBeNull();
    expect(safeColor(null)).toBeNull();
  });
  it("converts to rgb channels", () => expect(hexToRgbChannels("#c2410c")).toBe("194 65 12"));
});

describe("lineKey", () => {
  it("merges same dish + same extras in any order, splits otherwise", () => {
    expect(lineKey("a", ["x", "y"], "No Onion")).toBe(lineKey("a", ["y", "x"], " no onion "));
    expect(lineKey("a", ["x"])).not.toBe(lineKey("a", []));
  });
});

describe("formatMoney / translate", () => {
  it("formats with symbol and decimals", () => expect(formatMoney(1250, { symbol: "৳", decimals: 0, locale: "en-BD" })).toBe("৳1,250"));
  it("falls back to English for untranslated keys and fills variables", () => {
    expect(translate("bn", "auth.sent", { phone: "017" })).toContain("017");
    expect(translate("en", "co.minOrder", { amount: "৳500" })).toBe("Minimum order is ৳500.");
  });
});

import { nextDelay } from "./live-order";
describe("nextDelay", () => {
  it("polls briskly at first, calmer later, rarely when push is live or the tab is hidden", () => {
    expect(nextDelay({ pushLive: false, hidden: false, failures: 0, ageMs: 1000 })).toBe(5000);
    expect(nextDelay({ pushLive: false, hidden: false, failures: 0, ageMs: 11 * 60000 })).toBe(15000);
    expect(nextDelay({ pushLive: true, hidden: false, failures: 0, ageMs: 0 })).toBe(60000);
    expect(nextDelay({ pushLive: false, hidden: true, failures: 0, ageMs: 0 })).toBe(30000);
  });
  it("backs off on errors up to a minute", () => {
    expect([1, 2, 3, 4, 9].map((f) => nextDelay({ pushLive: false, hidden: false, failures: f, ageMs: 0 }))).toEqual([10000, 20000, 40000, 60000, 60000]);
  });
});
