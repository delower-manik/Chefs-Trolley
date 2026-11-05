import { describe, expect, it } from "vitest";
import { en } from "../en";
import { bn } from "../bn";
import { translate } from "../i18n-provider";
import { resolveTheme } from "@/lib/theme/theme-provider";
import { NAVIGATION } from "@/config/navigation";

describe("i18n", () => {
  it("every Bangla key exists in English", () => {
    for (const k of Object.keys(bn)) expect(k in en).toBe(true);
  });
  it("every navigation label has an English and a Bangla translation", () => {
    for (const g of NAVIGATION) {
      expect(`nav.group.${g.group}` in bn).toBe(true);
      for (const i of g.items) {
        expect(`nav.${i.id}` in en).toBe(true);
        expect(`nav.${i.id}` in bn).toBe(true);
      }
    }
  });
  it("falls back to English, then the key", () => {
    expect(translate("bn", "nav.dashboard")).toBe("ড্যাশবোর্ড");
    expect(translate("en", "nav.dashboard")).toBe("Dashboard");
    expect(translate("bn", "nope" as never)).toBe("nope");
  });
});

describe("theme", () => {
  it("resolves system, light and dark", () => {
    expect(resolveTheme("system", true)).toBe("light");
    expect(resolveTheme("system", false)).toBe("dark");
    expect(resolveTheme("dark", true)).toBe("dark");
    expect(resolveTheme("light", false)).toBe("light");
  });
});
