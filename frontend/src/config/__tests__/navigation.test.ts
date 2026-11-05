import { describe, expect, it } from "vitest";
import { ALL_NAV_ITEMS, NAVIGATION } from "@/config/navigation";

describe("navigation config", () => {
  it("has no duplicate item ids across groups", () => {
    const ids = ALL_NAV_ITEMS.map((item) => item.id);
    expect(new Set(ids).size).toBe(ids.length);
  });

  it("has no duplicate hrefs", () => {
    const hrefs = ALL_NAV_ITEMS.map((item) => item.href);
    expect(new Set(hrefs).size).toBe(hrefs.length);
  });

  it("every item's phase is a positive integer", () => {
    for (const item of ALL_NAV_ITEMS) {
      expect(Number.isInteger(item.phase)).toBe(true);
      expect(item.phase).toBeGreaterThan(0);
    }
  });

  it("every group has at least one item", () => {
    for (const group of NAVIGATION) {
      expect(group.items.length).toBeGreaterThan(0);
    }
  });

  it("implemented items currently in the app all belong to a built phase (1-17)", () => {
    // Guards against silently flipping `implemented: true` on a route that
    // was never actually built for a later phase.
    const implemented = ALL_NAV_ITEMS.filter((item) => item.implemented);
    for (const item of implemented) {
      expect([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17]).toContain(item.phase);
    }
  });
});
