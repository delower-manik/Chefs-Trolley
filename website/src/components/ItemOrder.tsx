"use client";

import { useState } from "react";
import { ItemDialog } from "./MenuView";
import { useApp } from "./providers";
import type { MenuItem } from "@/lib/types";

/** Add-to-cart for the single-dish page (same options dialog as the menu). */
export default function ItemOrder({ item, branchId }: { item: MenuItem; branchId: string }) {
  const { t, site, addLine, path } = useApp();
  const [open, setOpen] = useState(false);
  const [done, setDone] = useState(false);
  if (!site.ordering.enabled) return <p className="text-sm text-soft">{t("home.closed")}</p>;
  const quick = () => {
    if (item.modifierGroups.length) return setOpen(true);
    addLine(branchId, { itemId: item.id, name: item.name, unitPrice: item.price, quantity: 1, modifierIds: [], modifierNames: [] });
    setDone(true);
  };
  return (
    <div>
      <button type="button" className="btn" onClick={quick}>{t("menu.addToCart")}</button>
      {done && <a className="ml-3 text-sm underline" href={path("/cart")}>{t("nav.cart")}</a>}
      {open && <ItemDialog item={item} onClose={() => setOpen(false)} onAdd={(l) => { addLine(branchId, l); setOpen(false); setDone(true); }} />}
    </div>
  );
}
