import { describe, expect, it } from "vitest";
import { clampToPlan, comboSavings, comboWindowLabel, isoToLocalInput, localInputToIso, sizeForShape, snap } from "@/lib/restaurant-utils";

describe("floor plan helpers", () => {
  it("snaps to the grid", () => {
    expect(snap(14)).toBe(10);
    expect(snap(15)).toBe(20);
    expect(snap(0)).toBe(0);
  });

  it("keeps a table inside the plan", () => {
    const plan = { width: 1000, height: 600 };
    const size = { width: 80, height: 80 };
    expect(clampToPlan(-50, -5, size, plan)).toEqual({ x: 0, y: 0 });
    expect(clampToPlan(990, 590, size, plan)).toEqual({ x: 920, y: 520 });
    expect(clampToPlan(100.4, 200.6, size, plan)).toEqual({ x: 100, y: 201 });
  });

  it("never returns a negative max for a table larger than the plan", () => {
    expect(clampToPlan(50, 50, { width: 500, height: 500 }, { width: 200, height: 200 })).toEqual({ x: 0, y: 0 });
  });

  it("makes round tables square", () => {
    expect(sizeForShape("round", 80, 120)).toEqual({ width: 120, height: 120 });
    expect(sizeForShape("rect", 80, 120)).toEqual({ width: 80, height: 120 });
  });
});

describe("combo helpers", () => {
  it("computes savings and never goes negative", () => {
    expect(comboSavings({ price: 400, regularPrice: 500 })).toBe(100);
    expect(comboSavings({ price: 600, regularPrice: 500 })).toBe(0);
    expect(comboSavings({ price: 99.99, regularPrice: 100 })).toBe(0.01);
  });

  it("describes the schedule window", () => {
    expect(comboWindowLabel({ startsAt: null, endsAt: null })).toBe("Always available");
    expect(comboWindowLabel({ startsAt: "2026-01-01T00:00:00Z", endsAt: null })).toMatch(/^From /);
    expect(comboWindowLabel({ startsAt: null, endsAt: "2026-01-01T00:00:00Z" })).toMatch(/^Until /);
    expect(comboWindowLabel({ startsAt: "2026-01-01T00:00:00Z", endsAt: "2026-01-02T00:00:00Z" })).toContain("→");
  });

  it("round-trips datetime-local values", () => {
    expect(localInputToIso("")).toBeNull();
    expect(isoToLocalInput(null)).toBe("");
    const iso = localInputToIso("2026-03-04T05:06");
    expect(iso).not.toBeNull();
    expect(isoToLocalInput(iso)).toBe("2026-03-04T05:06");
  });
});
