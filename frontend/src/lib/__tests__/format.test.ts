import { describe, expect, it } from "vitest";
import { formatBDT, formatDate, formatDateTime, startOfMonthInDhaka, todayInDhaka } from "@/lib/format";

describe("formatBDT", () => {
  it("formats a whole number as BDT currency", () => {
    // Node's ICU build determines whether it renders the ৳ glyph or the
    // "BDT" code; the currency identifier is what actually matters here.
    const result = formatBDT(1500);
    expect(result).toContain("1,500");
    expect(result).toMatch(/৳|BDT/);
  });

  it("rounds to whole taka (no decimal places)", () => {
    expect(formatBDT(1499.99)).not.toContain(".");
  });

  it("handles zero", () => {
    expect(formatBDT(0)).toContain("0");
  });
});

describe("formatDateTime", () => {
  it("renders a readable date and time in the given timezone", () => {
    const result = formatDateTime("2026-01-15T10:30:00Z", "Asia/Dhaka");
    expect(result).toMatch(/2026/);
  });
});

describe("Dhaka date helpers", () => {
  it("uses the Dhaka calendar day, not the UTC one", () => {
    // 20:00 UTC on 10 March is 02:00 on 11 March in Dhaka (UTC+6).
    const lateEveningUtc = new Date("2026-03-10T20:00:00Z");
    expect(todayInDhaka(lateEveningUtc)).toBe("2026-03-11");
  });

  it("returns the first of the month for the start-of-month helper", () => {
    expect(startOfMonthInDhaka(new Date("2026-03-20T05:00:00Z"))).toBe("2026-03-01");
    // 19:00 UTC on 31 March is already 1 April in Dhaka.
    expect(startOfMonthInDhaka(new Date("2026-03-31T19:00:00Z"))).toBe("2026-04-01");
  });
});

describe("formatDate", () => {
  it("renders a plain date without shifting the day", () => {
    const result = formatDate("2026-03-11");
    expect(result).toMatch(/11/);
    expect(result).toMatch(/2026/);
  });
});
