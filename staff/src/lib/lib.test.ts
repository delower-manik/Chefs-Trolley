import { describe, expect, it } from "vitest";
import { ageTone, cartPayload, cartTotal, groupsOk, lineKey, minutesAgo } from "./format";
import { translate } from "./i18n";

describe("cart", () => {
  const g = (o: object) => ({ isRequired: false, minSelect: null, maxSelect: null, modifiers: [{ id: "a" }, { id: "b" }], ...o });
  it("same dish/modifiers/note merge, order of modifiers does not matter", () => expect(lineKey("x", ["b", "a"], " hi ")).toBe(lineKey("x", ["a", "b"], "hi")));
  it("required group needs a pick; max is enforced", () => {
    expect(groupsOk([g({ isRequired: true, minSelect: 1, maxSelect: 1 })], [])).toBe(false);
    expect(groupsOk([g({ isRequired: true, minSelect: 1, maxSelect: 1 })], ["a"])).toBe(true);
    expect(groupsOk([g({ isRequired: true, minSelect: 1, maxSelect: 1 })], ["a", "b"])).toBe(false);
    expect(groupsOk([g({})], [])).toBe(true);
  });
  it("payload drops zero lines and empty fields; total is display-only", () => {
    const cart = { k1: { itemId: "x", quantity: 2, modifierIds: [], notes: " " }, k2: { itemId: "y", quantity: 0, modifierIds: ["a"], notes: "" } };
    expect(cartPayload(cart)).toEqual([{ food_item_id: "x", quantity: 2, notes: undefined, modifier_ids: undefined }]);
    expect(cartTotal(cart, (id) => (id === "x" ? 100 : 5))).toBe(200);
  });
});
describe("misc", () => {
  it("ageTone", () => { expect(ageTone(3)).toBe("ok"); expect(ageTone(12)).toBe("warn"); expect(ageTone(25)).toBe("late"); expect(ageTone(null)).toBe("ok"); });
  it("minutesAgo", () => expect(minutesAgo("2026-01-01T00:00:00Z", Date.parse("2026-01-01T00:07:00Z"))).toBe(7));
  it("i18n", () => { expect(translate("en", "k.table", { t: "T4" })).toBe("Table T4"); expect(translate("bn", "w.free")).toBe("খালি"); });
});
