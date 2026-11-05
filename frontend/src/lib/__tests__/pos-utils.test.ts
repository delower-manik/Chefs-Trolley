import { describe, expect, it } from "vitest";
import { base64ToBytes, changeDue, percentOf, splitEqually, varianceOf } from "../pos-utils";

describe("pos-utils", () => {
  it("splits equally without losing a paisa", () => {
    expect(splitEqually(100, 3)).toEqual([33.34, 33.33, 33.33]);
    expect(splitEqually(100, 3).reduce((a, b) => Math.round((a + b) * 100) / 100, 0)).toBe(100);
    expect(splitEqually(10, 1)).toEqual([10]);
  });

  it("treats a non-positive number of ways as one", () => {
    expect(splitEqually(50, 0)).toEqual([50]);
  });

  it("computes change including the tip, never negative", () => {
    expect(changeDue(200, 10, 250)).toBe(40);
    expect(changeDue(200, 10, 100)).toBe(0);
  });

  it("rounds percentage discounts half-up", () => {
    expect(percentOf(200, 10)).toBe(20);
    expect(percentOf(0.05, 10)).toBe(0.01);
    expect(percentOf(333.33, 12.5)).toBe(41.67);
  });

  it("shows variance as counted minus expected", () => {
    expect(varianceOf(650, 655)).toBe(-5);
    expect(varianceOf(660.5, 655)).toBe(5.5);
  });

  it("decodes base64 to bytes", () => {
    expect(Array.from(base64ToBytes(btoa("\x1B@")))).toEqual([27, 64]);
  });
});
