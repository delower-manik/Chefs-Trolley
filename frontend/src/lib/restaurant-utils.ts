import type { Combo, TableShape } from "@/types/restaurant";

export const GRID = 10;

/** Snap a coordinate to the editor grid. */
export function snap(value: number, grid = GRID): number {
  return Math.round(value / grid) * grid;
}

/** Keep a table fully inside the plan; returns whole-number coordinates. */
export function clampToPlan(
  x: number,
  y: number,
  size: { width: number; height: number },
  plan: { width: number; height: number }
): { x: number; y: number } {
  const maxX = Math.max(0, plan.width - size.width);
  const maxY = Math.max(0, plan.height - size.height);
  return { x: Math.min(Math.max(0, Math.round(x)), maxX), y: Math.min(Math.max(0, Math.round(y)), maxY) };
}

/** A round table is drawn square-bounded; keep it square when switching shape. */
export function sizeForShape(shape: TableShape, width: number, height: number): { width: number; height: number } {
  if (shape === "round") {
    const side = Math.max(width, height);
    return { width: side, height: side };
  }
  return { width, height };
}

/** How much a combo saves versus buying its dishes separately (never negative). */
export function comboSavings(combo: Pick<Combo, "price" | "regularPrice">): number {
  return Math.max(0, Math.round((combo.regularPrice - combo.price) * 100) / 100);
}

/** Human label for a combo's schedule window. */
export function comboWindowLabel(combo: Pick<Combo, "startsAt" | "endsAt">, timeZone = "Asia/Dhaka"): string {
  const fmt = (iso: string) =>
    new Intl.DateTimeFormat("en-BD", { dateStyle: "medium", timeStyle: "short", timeZone }).format(new Date(iso));
  if (combo.startsAt && combo.endsAt) return `${fmt(combo.startsAt)} → ${fmt(combo.endsAt)}`;
  if (combo.startsAt) return `From ${fmt(combo.startsAt)}`;
  if (combo.endsAt) return `Until ${fmt(combo.endsAt)}`;
  return "Always available";
}

/** datetime-local input value (browser-local, no zone) → ISO string, or null when empty. */
export function localInputToIso(value: string): string | null {
  return value ? new Date(value).toISOString() : null;
}

/** ISO string → value for a datetime-local input in the browser's zone. */
export function isoToLocalInput(iso: string | null): string {
  if (!iso) return "";
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
