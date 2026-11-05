"use client";

import L from "@/components/L";
import { useApp } from "@/components/providers";

export default function CartPage() {
  const { t, money, cart, setQty, cartTotal, site } = useApp();
  if (!cart.lines.length)
    return (<div className="card text-center"><p>{t("cart.empty")}</p><L href="/menu" className="btn mt-4">{t("nav.menu")}</L></div>);
  return (
    <div>
      <h1 className="mb-4 text-3xl font-bold">{t("cart.title")}</h1>
      <ul className="space-y-3">
        {cart.lines.map((l) => (
          <li key={l.key} className="card flex items-center gap-3">
            <div className="min-w-0 flex-1">
              <p className="font-semibold">{l.name}</p>
              {l.modifierNames.length > 0 && <p className="text-sm text-soft">{l.modifierNames.join(", ")}</p>}
              {l.notes && <p className="text-sm italic text-soft">“{l.notes}”</p>}
              <p className="text-sm">{money(l.unitPrice)}</p>
            </div>
            <div className="flex items-center gap-2">
              <button type="button" className="btn-ghost !px-3" aria-label="−" onClick={() => setQty(l.key, l.quantity - 1)}>−</button>
              <span className="w-5 text-center">{l.quantity}</span>
              <button type="button" className="btn-ghost !px-3" aria-label="+" onClick={() => setQty(l.key, l.quantity + 1)}>+</button>
            </div>
          </li>
        ))}
      </ul>
      <div className="mt-4 flex items-center justify-between text-lg font-semibold"><span>{t("cart.subtotal")}</span><span>{money(cartTotal)}</span></div>
      {site.ordering.minOrder > 0 && cartTotal < site.ordering.minOrder && <p className="mt-1 text-sm text-amber-700">{t("co.minOrder", { amount: money(site.ordering.minOrder) })}</p>}
      <L href="/checkout" className="btn mt-4 w-full">{t("cart.checkout")}</L>
    </div>
  );
}
