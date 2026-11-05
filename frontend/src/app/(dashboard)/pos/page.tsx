"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth/auth-context";
import { usePosCatalog } from "@/hooks/use-restaurant";
import { useDeliveryZones } from "@/hooks/use-delivery";
import { createOrder } from "@/hooks/use-orders";
import { formatBDT } from "@/lib/format";
import { ApiError, isNetworkError } from "@/lib/api/client";
import { enqueue, summarise } from "@/lib/offline-queue";
import { useOfflineQueue } from "@/components/layout/offline-queue-provider";
import type { CatalogCombo, CatalogItem as FoodItem, CatalogModifier as Modifier } from "@/types/restaurant";
import type { CreateOrderPayload, OrderType } from "@/types/order";

type CartLine = {
  key: string; // foodItemId + sorted modifier ids, so re-adding the same combo increments quantity
  foodItem?: FoodItem;
  combo?: CatalogCombo;
  quantity: number;
  selectedModifiers: Modifier[];
  notes?: string;
};

function lineKey(foodItemId: string, modifierIds: string[]): string {
  return `${foodItemId}::${[...modifierIds].sort().join(",")}`;
}

export default function PosPage() {
  const { user } = useAuth();
  const router = useRouter();
  const { catalog, isLoading, error: catalogError } = usePosCatalog();
  const categories = catalog?.categories ?? [];
  const [categoryId, setCategoryId] = useState<string>("");
  const items = useMemo(
    () => (catalog?.items ?? []).filter((i) => !categoryId || i.categoryId === categoryId),
    [catalog, categoryId]
  );
  const combos = catalog?.combos ?? [];
  const tables = catalog?.tables ?? [];
  const { zones } = useDeliveryZones();

  const [cart, setCart] = useState<CartLine[]>([]);
  const [orderType, setOrderType] = useState<OrderType>("pos");
  const [tableId, setTableId] = useState("");
  const [deliveryZoneId, setDeliveryZoneId] = useState("");
  const [deliveryAddress, setDeliveryAddress] = useState("");
  const [pickerFor, setPickerFor] = useState<FoodItem | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const { refresh: refreshQueue } = useOfflineQueue();

  const subtotal = useMemo(
    () =>
      cart.reduce((sum, line) => {
        const unit = (line.combo?.price ?? line.foodItem?.basePrice ?? 0) + line.selectedModifiers.reduce((s, m) => s + m.price, 0);
        return sum + unit * line.quantity;
      }, 0),
    [cart]
  );

  function addToCart(foodItem: FoodItem, selectedModifiers: Modifier[] = []) {
    const key = lineKey(foodItem.id, selectedModifiers.map((m) => m.id));
    setCart((prev) => {
      const existing = prev.find((l) => l.key === key);
      if (existing) {
        return prev.map((l) => (l.key === key ? { ...l, quantity: l.quantity + 1 } : l));
      }
      return [...prev, { key, foodItem, quantity: 1, selectedModifiers }];
    });
  }

  function addCombo(combo: CatalogCombo) {
    const key = `combo::${combo.id}`;
    setCart((prev) => {
      const existing = prev.find((l) => l.key === key);
      if (existing) return prev.map((l) => (l.key === key ? { ...l, quantity: l.quantity + 1 } : l));
      return [...prev, { key, combo, quantity: 1, selectedModifiers: [] }];
    });
  }

  function handleItemClick(item: FoodItem) {
    if (item.modifierGroups.length > 0) {
      setPickerFor(item);
    } else {
      addToCart(item);
    }
  }

  function updateQuantity(key: string, delta: number) {
    setCart((prev) =>
      prev.flatMap((l) => {
        if (l.key !== key) return [l];
        const next = l.quantity + delta;
        return next <= 0 ? [] : [{ ...l, quantity: next }];
      })
    );
  }

  async function handleCheckout() {
    if (cart.length === 0) return;
    setSubmitting(true);
    setError(null);
    setNotice(null);
    const payload: CreateOrderPayload = {
      source: orderType,
      dining_table_id: orderType === "dine_in" ? tableId || undefined : undefined,
      delivery_zone_id: orderType === "delivery" ? deliveryZoneId || undefined : undefined,
      delivery_address: orderType === "delivery" ? deliveryAddress || undefined : undefined,
      items: cart.map((l) =>
        l.combo
          ? { combo_id: l.combo.id, quantity: l.quantity }
          : { food_item_id: l.foodItem!.id, quantity: l.quantity, modifier_ids: l.selectedModifiers.map((m) => m.id) }
      ),
    };
    // One key per order, reused if we have to queue it: a timeout that actually reached the server can never become two orders.
    const key = crypto.randomUUID();
    try {
      if (!navigator.onLine) throw new TypeError("offline");
      const order = await createOrder(payload, key);
      setCart([]);
      router.push(`/orders/${order.data.id}`);
    } catch (err) {
      if (isNetworkError(err) && user?.activeBranch) {
        const saved = enqueue({
          key, branchId: user.activeBranch.id, payload, createdAt: new Date().toISOString(),
          label: summarise(cart.map((l) => ({ quantity: l.quantity, name: l.combo?.name ?? l.foodItem?.name ?? "item" }))),
        });
        if (saved) {
          setCart([]);
          refreshQueue();
          setNotice("No connection: the order is saved on this device and will be sent automatically. Payment and printing wait until it is sent.");
        } else setError("No connection and this device could not store the order. Keep the cart and try again when online.");
      } else {
        setError(err instanceof ApiError ? err.message : "Couldn't send this order. Try again.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <section className="h-full flex flex-col lg:flex-row gap-4 -m-4 md:-m-6 p-4 md:p-6">
      {/* Item grid */}
      <div className="flex-1 flex flex-col min-h-0">
        <div className="flex gap-2 overflow-x-auto pb-3 shrink-0">
          <button
            onClick={() => setCategoryId("")}
            className={`px-3 py-1.5 rounded-full text-sm whitespace-nowrap ${
              categoryId === "" ? "bg-primary text-on-primary" : "bg-surface-hover text-text-secondary"
            }`}
          >
            All
          </button>
          {categories.map((c) => (
            <button
              key={c.id}
              onClick={() => setCategoryId(c.id)}
              className={`px-3 py-1.5 rounded-full text-sm whitespace-nowrap ${
                categoryId === c.id ? "bg-primary text-on-primary" : "bg-surface-hover text-text-secondary"
              }`}
            >
              {c.name}
            </button>
          ))}
        </div>

        <div className="flex-1 overflow-y-auto grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3 content-start">
          {isLoading
            ? Array.from({ length: 8 }).map((_, i) => <div key={i} className="h-28 bg-surface-hover rounded-card animate-pulse" />)
            : [
                ...combos.map((combo) => (
                  <button
                    key={`combo-${combo.id}`}
                    onClick={() => addCombo(combo)}
                    className="card p-3 text-left border-primary/40 hover:border-primary transition-colors"
                  >
                    <div className="text-[10px] text-primary-fg font-semibold uppercase">Combo</div>
                    <div className="font-semibold text-strong text-sm">{combo.name}</div>
                    <div className="text-xs text-text-secondary mt-1">{formatBDT(combo.price)}</div>
                    <div className="text-[10px] text-text-muted mt-1">{combo.items.map((i) => `${i.quantity}× ${i.name}`).join(", ")}</div>
                  </button>
                )),
                ...items.map((item) => (
                <button
                  key={item.id}
                  onClick={() => handleItemClick(item)}
                  className="card p-3 text-left hover:border-primary transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                >
                  <div className="font-semibold text-strong text-sm">{item.name}</div>
                  <div className="text-xs text-text-secondary mt-1">{formatBDT(item.basePrice)}</div>
                  {item.modifierGroups.length > 0 && (
                    <div className="text-[10px] text-primary-fg mt-1">Customizable</div>
                  )}
                </button>
                )),
              ]}
        </div>
        {catalogError && <div className="text-sm text-danger mt-3">Couldn&apos;t load the menu. Check your connection and permissions.</div>}
      </div>

      {/* Cart */}
      <div className="w-full lg:w-96 shrink-0 card p-4 flex flex-col min-h-0">
        <h3 className="font-bold text-strong mb-3">Current Order</h3>

        <div className="flex gap-2 mb-3">
          <select aria-label="Order type" value={orderType} onChange={(e) => setOrderType(e.target.value as OrderType)} className="input-field text-sm flex-1">
            <option value="pos">Counter</option>
            <option value="dine_in">Dine-in</option>
            <option value="takeaway">Takeaway</option>
            <option value="delivery">Delivery</option>
          </select>
          {orderType === "dine_in" && (
            <select aria-label="Table" value={tableId} onChange={(e) => setTableId(e.target.value)} className="input-field text-sm flex-1">
              <option value="">Table</option>
              {tables.map((t) => (
                <option key={t.id} value={t.id} disabled={!!t.openOrder || t.status === "out_of_service"}>
                  {t.label}{t.openOrder ? " (open tab)" : ""}
                </option>
              ))}
            </select>
          )}
        </div>

        {orderType === "delivery" && (
          <div className="space-y-2 mb-3">
            <select
              aria-label="Delivery zone"
              value={deliveryZoneId}
              onChange={(e) => setDeliveryZoneId(e.target.value)}
              className="input-field text-sm w-full"
            >
              <option value="">Delivery zone (sets fee)</option>
              {zones.map((z) => (
                <option key={z.id} value={z.id}>
                  {z.name} — {formatBDT(z.deliveryFee)}
                </option>
              ))}
            </select>
            <input
              value={deliveryAddress}
              onChange={(e) => setDeliveryAddress(e.target.value)}
              className="input-field text-sm w-full"
              placeholder="Delivery address"
            />
          </div>
        )}

        <div className="flex-1 overflow-y-auto space-y-2 -mx-1 px-1">
          {cart.length === 0 ? (
            <div className="text-sm text-text-muted text-center py-10">Tap an item to add it to the order.</div>
          ) : (
            cart.map((line) => (
              <div key={line.key} className="flex items-center justify-between text-sm border-b border-border pb-2">
                <div>
                  <div className="text-strong">{line.combo?.name ?? line.foodItem?.name}{line.combo && <span className="badge bg-primary/15 text-primary-fg ml-1.5">Combo</span>}</div>
                  {line.selectedModifiers.length > 0 && (
                    <div className="text-xs text-text-secondary">{line.selectedModifiers.map((m) => m.name).join(", ")}</div>
                  )}
                </div>
                <div className="flex items-center gap-2">
                  <button onClick={() => updateQuantity(line.key, -1)} className="w-6 h-6 rounded bg-surface-hover text-strong">
                    −
                  </button>
                  <span className="w-4 text-center text-strong">{line.quantity}</span>
                  <button onClick={() => updateQuantity(line.key, 1)} className="w-6 h-6 rounded bg-surface-hover text-strong">
                    +
                  </button>
                </div>
              </div>
            ))
          )}
        </div>

        {notice && <div role="status" className="bg-warning/10 border border-warning/30 text-text-primary text-xs rounded-lg px-3 py-2 mt-3">{notice}</div>}
        {error && <div className="bg-danger/10 border border-danger/30 text-danger text-xs rounded-lg px-3 py-2 mt-3">{error}</div>}

        <div className="border-t border-border mt-3 pt-3">
          <div className="flex justify-between font-bold text-strong mb-3">
            <span>Subtotal</span>
            <span>{formatBDT(subtotal)}</span>
          </div>
          <p className="text-[11px] text-text-muted mb-3">Tax and any delivery fee are calculated server-side at checkout.</p>
          <button onClick={handleCheckout} disabled={cart.length === 0 || submitting} className="btn-primary w-full py-3">
            {submitting ? "Sending to kitchen..." : "Send order"}
          </button>
        </div>
      </div>

      {pickerFor && (
        <ModifierPicker
          item={pickerFor}
          onClose={() => setPickerFor(null)}
          onConfirm={(mods) => {
            addToCart(pickerFor, mods);
            setPickerFor(null);
          }}
        />
      )}
    </section>
  );
}

function ModifierPicker({
  item,
  onClose,
  onConfirm,
}: {
  item: FoodItem;
  onClose: () => void;
  onConfirm: (modifiers: Modifier[]) => void;
}) {
  const [selected, setSelected] = useState<Record<string, Modifier[]>>({});

  function toggle(groupId: string, modifier: Modifier, maxSelect: number) {
    setSelected((prev) => {
      const current = prev[groupId] ?? [];
      const exists = current.some((m) => m.id === modifier.id);
      let next: Modifier[];
      if (exists) {
        next = current.filter((m) => m.id !== modifier.id);
      } else if (maxSelect === 1) {
        next = [modifier];
      } else if (current.length < maxSelect) {
        next = [...current, modifier];
      } else {
        next = current;
      }
      return { ...prev, [groupId]: next };
    });
  }

  const canConfirm = item.modifierGroups
    .filter((g) => g.isRequired)
    .every((g) => (selected[g.id]?.length ?? 0) >= Math.max(1, g.minSelect));

  return (
    <div className="fixed inset-0 bg-black/60 grid place-items-center z-50 p-4" onClick={onClose}>
      <div className="card p-5 w-full max-w-md max-h-[80vh] overflow-y-auto" onClick={(e) => e.stopPropagation()}>
        <h3 className="font-bold text-strong mb-1">{item.name}</h3>
        <p className="text-xs text-text-secondary mb-4">Choose options before adding to the order.</p>

        {item.modifierGroups.map((group) => (
          <div key={group.id} className="mb-4">
            <div className="text-sm font-semibold text-strong mb-2">
              {group.name} {group.isRequired && <span className="text-danger">*</span>}
            </div>
            <div className="space-y-1.5">
              {group.modifiers.map((mod) => {
                const isSelected = (selected[group.id] ?? []).some((m) => m.id === mod.id);
                return (
                  <button
                    key={mod.id}
                    onClick={() => toggle(group.id, mod, group.maxSelect)}
                    className={`w-full flex justify-between items-center px-3 py-2 rounded-lg text-sm border ${
                      isSelected ? "border-primary bg-primary/10 text-strong" : "border-border text-text-secondary"
                    }`}
                  >
                    <span>{mod.name}</span>
                    <span>{mod.price > 0 ? `+${formatBDT(mod.price)}` : "Free"}</span>
                  </button>
                );
              })}
            </div>
          </div>
        ))}

        <div className="flex gap-2 justify-end mt-4">
          <button onClick={onClose} className="btn-secondary">
            Cancel
          </button>
          <button
            disabled={!canConfirm}
            onClick={() => onConfirm(Object.values(selected).flat())}
            className="btn-primary"
          >
            Add to order
          </button>
        </div>
      </div>
    </div>
  );
}
