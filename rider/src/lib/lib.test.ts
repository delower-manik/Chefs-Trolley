import { describe, expect, it } from "vitest";
import { distanceMeters, minutesAgo, shouldSend, telHref } from "./format";
import { translate } from "./i18n";

describe("telHref", () => {
  it("accepts common spellings", () => { expect(telHref("+880 1711-123456")).toBe("tel:+8801711123456"); expect(telHref("01711123456")).toBe("tel:01711123456"); });
  it("rejects junk", () => { expect(telHref("abc")).toBeNull(); expect(telHref(null)).toBeNull(); });
});
describe("shouldSend", () => {
  const a = { lat: 23.8, lng: 90.4, at: 0 };
  it("first fix always", () => expect(shouldSend(null, a)).toBe(true));
  it("not within 15s", () => expect(shouldSend(a, { lat: 24, lng: 91, at: 10000 })).toBe(false));
  it("skips tiny moves, sends big ones, and heartbeats at 60s", () => {
    expect(shouldSend(a, { ...a, at: 20000 })).toBe(false);
    expect(shouldSend(a, { lat: 23.81, lng: 90.4, at: 20000 })).toBe(true);
    expect(shouldSend(a, { ...a, at: 61000 })).toBe(true);
  });
});
describe("misc", () => {
  it("distance ~111km per degree", () => expect(Math.round(distanceMeters({ lat: 0, lng: 0 }, { lat: 1, lng: 0 }) / 1000)).toBe(111));
  it("minutesAgo", () => { expect(minutesAgo("2026-01-01T00:00:00Z", Date.parse("2026-01-01T00:05:30Z"))).toBe(5); expect(minutesAgo(null)).toBeNull(); });
  it("i18n falls back and interpolates", () => { expect(translate("bn", "min.ago", { n: 3 })).toContain("3"); expect(translate("en", "d.confirmCash", { amount: "৳5" })).toBe("I collected exactly ৳5"); });
});
