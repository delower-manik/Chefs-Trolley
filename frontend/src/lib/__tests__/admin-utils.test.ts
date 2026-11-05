import { describe, expect, it } from "vitest";
import {
  actionCategory,
  categoriesOf,
  firstFieldErrors,
  groupPermissions,
  toQuery,
  toggleInList,
} from "@/lib/admin-utils";

describe("toggleInList", () => {
  it("adds a missing id and removes a present one without mutating the input", () => {
    const original = ["a", "b"];
    expect(toggleInList(original, "c")).toEqual(["a", "b", "c"]);
    expect(toggleInList(original, "a")).toEqual(["b"]);
    expect(original).toEqual(["a", "b"]);
  });
});

describe("groupPermissions", () => {
  it("groups by module and sorts both the modules and the keys", () => {
    const grouped = groupPermissions([
      { name: "orders.view" },
      { name: "menu.manage" },
      { name: "orders.cancel" },
      { name: "standalone" },
    ]);

    expect(grouped.map((g) => g.module)).toEqual(["menu", "orders", "other"]);
    expect(grouped[1]?.items.map((i) => i.name)).toEqual(["orders.cancel", "orders.view"]);
  });

  it("keeps extra fields on the items", () => {
    const grouped = groupPermissions([{ name: "orders.view", grantable: false }]);
    expect(grouped[0]?.items[0]?.grantable).toBe(false);
  });

  it("returns an empty list for no permissions", () => {
    expect(groupPermissions([])).toEqual([]);
  });
});

describe("activity helpers", () => {
  it("derives the category from an action", () => {
    expect(actionCategory("order.cancelled")).toBe("order");
    expect(actionCategory("auth.login")).toBe("auth");
    expect(actionCategory("nodots")).toBe("nodots");
  });

  it("lists distinct, sorted categories", () => {
    expect(categoriesOf(["user.created", "order.cancelled", "order.created", "auth.login"])).toEqual([
      "auth",
      "order",
      "user",
    ]);
  });
});

describe("toQuery", () => {
  it("skips empty, null and undefined values and encodes the rest", () => {
    expect(toQuery({ q: "a b", status: "", page: 2, from: undefined, to: null })).toBe("q=a+b&page=2");
  });
});

describe("firstFieldErrors", () => {
  it("returns the first message of each field", () => {
    expect(firstFieldErrors({ email: ["Taken.", "Other."], password: ["Too short."] })).toEqual(["Taken.", "Too short."]);
    expect(firstFieldErrors(undefined)).toEqual([]);
  });
});
