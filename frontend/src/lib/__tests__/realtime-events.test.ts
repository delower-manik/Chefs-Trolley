import { describe, expect, it } from "vitest";
import { pathOf, shouldRevalidate } from "../realtime/events";

describe("realtime event → cache invalidation", () => {
  it("strips the query string", () => {
    expect(pathOf("/orders?page=2&status=pending")).toBe("/orders");
    expect(pathOf("/pos/catalog")).toBe("/pos/catalog");
  });

  it("order changes refresh orders, the kitchen board, POS catalog and tables", () => {
    for (const key of ["/orders", "/orders?page=1", "/orders/abc-123", "/kitchen/tickets", "/kitchen/tickets?station_id=x", "/pos/catalog", "/tables", "/floor-plans"]) {
      expect(shouldRevalidate("order.changed", key), key).toBe(true);
    }
  });

  it("matches whole path segments only", () => {
    expect(shouldRevalidate("order.changed", "/orders-archive")).toBe(false);
    expect(shouldRevalidate("order.changed", "/tablesaw")).toBe(false);
  });

  it("does not refresh unrelated data", () => {
    expect(shouldRevalidate("order.changed", "/customers")).toBe(false);
    expect(shouldRevalidate("order.changed", "/notification-deliveries")).toBe(false);
    expect(shouldRevalidate("delivery.changed", "/kitchen/tickets")).toBe(false);
  });

  it("ignores unknown events and non-string keys", () => {
    expect(shouldRevalidate("mystery.event", "/orders")).toBe(false);
    expect(shouldRevalidate("order.changed", ["/orders", 1])).toBe(false);
    expect(shouldRevalidate("order.changed", undefined)).toBe(false);
  });
});
