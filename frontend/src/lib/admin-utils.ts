/** Small pure helpers shared by the administration screens. */

/** Adds the id if absent, removes it if present -- for checkbox groups. */
export function toggleInList(list: string[], id: string): string[] {
  return list.includes(id) ? list.filter((x) => x !== id) : [...list, id];
}

/**
 * Groups permission keys like "orders.view" under their module ("orders"),
 * modules and keys both sorted, so a long permission list stays scannable.
 */
export function groupPermissions<T extends { name: string }>(items: T[]): { module: string; items: T[] }[] {
  const groups = new Map<string, T[]>();
  for (const item of items) {
    const group = item.name.includes(".") ? item.name.slice(0, item.name.indexOf(".")) : "other";
    groups.set(group, [...(groups.get(group) ?? []), item]);
  }
  return [...groups.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([group, list]) => ({ module: group, items: [...list].sort((x, y) => x.name.localeCompare(y.name)) }));
}

/** "order.cancelled" -> "order"; used to colour and filter activity entries. */
export function actionCategory(action: string): string {
  const dot = action.indexOf(".");
  return dot === -1 ? action : action.slice(0, dot);
}

/** Distinct categories ("order", "user", ...) from a list of actions, sorted. */
export function categoriesOf(actions: string[]): string[] {
  return [...new Set(actions.map(actionCategory))].sort((a, b) => a.localeCompare(b));
}

/** Turns an object of filters into a query string, skipping empty values. */
export function toQuery(params: Record<string, string | number | undefined | null>): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== "") search.set(key, String(value));
  }
  return search.toString();
}

/** Pulls the per-field validation messages out of an ApiError-shaped object, if any. */
export function firstFieldErrors(fieldErrors?: Record<string, string[]>): string[] {
  if (!fieldErrors) return [];
  return Object.values(fieldErrors).flatMap((messages) => messages.slice(0, 1));
}
