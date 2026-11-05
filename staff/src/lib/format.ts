export const money = (n: number, locale: string) => `৳${new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(n)}`;
export const minutesAgo = (iso: string | null, now = Date.now()): number | null => (iso ? Math.max(0, Math.floor((now - new Date(iso).getTime()) / 60000)) : null);
export const timeOf = (iso: string | null, locale: string) => (iso ? new Date(iso).toLocaleTimeString(locale, { hour: "2-digit", minute: "2-digit" }) : "–");

/** Kitchen tickets turn amber then red as they age, so a slow ticket is visible from across the pass. */
export const ageTone = (min: number | null): "ok" | "warn" | "late" => (min == null || min < 10 ? "ok" : min < 20 ? "warn" : "late");

export type Cart = Record<string, { itemId: string; quantity: number; modifierIds: string[]; notes: string }>;
export const lineKey = (itemId: string, modifierIds: string[], notes: string) => `${itemId}|${[...modifierIds].sort().join(",")}|${notes.trim()}`;
/** Display-only estimate. The server prices the order; what the waiter sees here is never what is charged. */
export function cartTotal(cart: Cart, price: (id: string, mods: string[]) => number): number {
  return Object.values(cart).reduce((s, l) => s + price(l.itemId, l.modifierIds) * l.quantity, 0);
}
/** The API payload for a cart. Lines with the same dish, modifiers and note are already merged by `lineKey`. */
export const cartPayload = (cart: Cart) => Object.values(cart).filter((l) => l.quantity > 0).map((l) => ({ food_item_id: l.itemId, quantity: l.quantity, notes: l.notes.trim() || undefined, modifier_ids: l.modifierIds.length ? l.modifierIds : undefined }));
/** Required modifier groups must be satisfied before the line can be added; mirrors, never replaces, server validation. */
export function groupsOk(groups: { isRequired: boolean; minSelect: number | null; maxSelect: number | null; modifiers: { id: string }[] }[], picked: string[]): boolean {
  return groups.every((g) => {
    const n = g.modifiers.filter((m) => picked.includes(m.id)).length;
    return n >= (g.isRequired ? Math.max(1, g.minSelect ?? 1) : g.minSelect ?? 0) && (g.maxSelect == null || g.maxSelect === 0 || n <= g.maxSelect);
  });
}
