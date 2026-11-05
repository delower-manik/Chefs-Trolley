"use client";

import { useState } from "react";
import { Can } from "@/components/ui/can";
import { ApiError } from "@/lib/api/client";
import { OnlinePayment } from "@/components/orders/online-payment";
import { lookupGiftCard } from "@/hooks/use-marketing";
import { applyDiscount, refundOrder, removeDiscount, takePayment, useCurrentShift, useOrderPayments, voidPayment } from "@/hooks/use-pos";
import { changeDue, percentOf, splitEqually } from "@/lib/pos-utils";
import { formatBDT } from "@/lib/format";
import type { Order } from "@/types/order";
import type { PaymentMethod } from "@/types/pos";

const METHODS: { id: PaymentMethod; label: string }[] = [
  { id: "cash", label: "Cash" },
  { id: "card", label: "Card" },
  { id: "mobile_banking", label: "Mobile banking" },
  { id: "gift_card", label: "Gift card" },
  { id: "other", label: "Other" },
];

const CLOSED = ["draft", "cancelled", "refunded"];

export function PaymentPanel({ order, onChanged }: { order: Order; onChanged: () => void }) {
  const { payments, summary, refresh } = useOrderPayments(order.id);
  const { shift } = useCurrentShift();
  const [method, setMethod] = useState<PaymentMethod>("cash");
  const [amount, setAmount] = useState("");
  const [tip, setTip] = useState("");
  const [tendered, setTendered] = useState("");
  const [reference, setReference] = useState("");
  const [giftCode, setGiftCode] = useState("");
  const [giftInfo, setGiftInfo] = useState<string | null>(null);
  const [ways, setWays] = useState(2);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ kind: "ok" | "err"; text: string } | null>(null);

  const due = summary?.balanceDue ?? order.balanceDue ?? order.grandTotal;
  const payAmount = amount === "" ? due : Number(amount);
  const tipValue = Number(tip) || 0;
  const livePayments = payments.filter((p) => !p.voidedAt);

  async function run(fn: () => Promise<unknown>, okText: string) {
    setBusy(true);
    setMsg(null);
    try {
      await fn();
      setMsg({ kind: "ok", text: okText });
      await refresh();
      onChanged();
    } catch (err) {
      setMsg({ kind: "err", text: err instanceof ApiError ? err.message : "That didn't work. Try again." });
    } finally {
      setBusy(false);
    }
  }

  async function pay(e: React.FormEvent) {
    e.preventDefault();
    await run(async () => {
      await takePayment(order.id, {
        method,
        amount: method === "gift_card" && amount === "" ? undefined : payAmount,
        tip: tipValue || undefined,
        tendered: method === "cash" && tendered !== "" ? Number(tendered) : undefined,
        reference: method === "gift_card" ? undefined : reference || undefined,
        gift_card_code: method === "gift_card" ? giftCode : undefined,
      });
      setGiftCode("");
      setGiftInfo(null);
      setAmount("");
      setTip("");
      setTendered("");
      setReference("");
    }, "Payment recorded.");
  }

  const closed = CLOSED.includes(order.status);

  return (
    <div className="card p-5 space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="font-bold text-strong">Payment</h3>
        <span className={`badge ${summary?.paymentState === "paid" ? "bg-success/15 text-success" : "bg-surface-hover text-text-secondary"}`}>
          {summary?.paymentState ?? order.paymentState}
        </span>
      </div>

      {msg && (
        <div className={`text-sm rounded-lg px-3 py-2 border ${msg.kind === "ok" ? "bg-success/10 border-success/30 text-success" : "bg-danger/10 border-danger/30 text-danger"}`} role="alert">
          {msg.text}
        </div>
      )}

      <div className="grid grid-cols-3 gap-2 text-sm text-center">
        <Stat label="Total" value={formatBDT(order.grandTotal)} />
        <Stat label="Paid (net)" value={formatBDT(summary?.net ?? 0)} />
        <Stat label="Balance" value={formatBDT(due)} strong />
      </div>

      {livePayments.length > 0 && (
        <ul className="divide-y divide-border text-sm">
          {payments.map((p) => (
            <li key={p.id} className={`py-2 flex items-center justify-between gap-2 ${p.voidedAt ? "opacity-50 line-through" : ""}`}>
              <div>
                <span className="text-strong capitalize">{p.kind === "refund" ? "Refund" : p.method.replace("_", " ")}</span>
                {p.tip > 0 && <span className="text-xs text-text-secondary"> · tip {formatBDT(p.tip)}</span>}
                {p.reason && <div className="text-xs text-text-secondary">{p.reason}</div>}
              </div>
              <div className="flex items-center gap-3">
                <span className={p.kind === "refund" ? "text-danger" : "text-strong"}>
                  {p.kind === "refund" ? "-" : ""}
                  {formatBDT(p.amount)}
                </span>
                {p.kind === "payment" && !p.voidedAt && (
                  <Can permission="orders.refund">
                    <button
                      className="text-xs text-text-secondary"
                      onClick={() => {
                        const reason = window.prompt("Why is this payment being voided?");
                        if (reason) void run(() => voidPayment(p.id, reason), "Payment voided.");
                      }}
                    >
                      Void
                    </button>
                  </Can>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}

      {!closed && due > 0 && (
        <Can permission="pos.access">
          <form onSubmit={pay} className="space-y-3 border-t border-border pt-4">
            <div className="flex gap-2 flex-wrap">
              {METHODS.map((m) => (
                <button type="button" key={m.id} onClick={() => setMethod(m.id)} className={`px-3 py-1.5 rounded-full text-sm ${method === m.id ? "bg-primary text-on-primary" : "bg-surface-hover text-text-secondary"}`}>
                  {m.label}
                </button>
              ))}
            </div>
            {method === "cash" && !shift && (
              <div className="text-xs text-warning">No open cash shift — open one in Cash Register before taking cash.</div>
            )}

            <div className="flex items-center gap-2 text-xs text-text-secondary">
              Split equally between
              <input type="number" min={2} max={20} value={ways} onChange={(e) => setWays(Number(e.target.value))} className="input-field w-16 py-1" />
              <button type="button" className="text-primary-fg" onClick={() => setAmount(String(splitEqually(due, ways)[0]))}>
                use one share ({formatBDT(splitEqually(due, ways)[0] ?? due)})
              </button>
            </div>

            <div className="grid sm:grid-cols-3 gap-3">
              <label className="text-xs text-text-secondary">Amount
                <input type="number" step="0.01" min={0.01} max={due} placeholder={String(due)} value={amount} onChange={(e) => setAmount(e.target.value)} className="input-field mt-1" />
              </label>
              <label className="text-xs text-text-secondary">Tip (optional)
                <input type="number" step="0.01" min={0} value={tip} onChange={(e) => setTip(e.target.value)} className="input-field mt-1" />
              </label>
              {method === "gift_card" ? (
                <label className="text-xs text-text-secondary">Gift card code
                  <input value={giftCode} onChange={(e) => setGiftCode(e.target.value)} maxLength={40} autoComplete="off" className="input-field mt-1 font-mono uppercase" />
                </label>
              ) : method === "cash" ? (
                <label className="text-xs text-text-secondary">Cash received
                  <input type="number" step="0.01" min={0} value={tendered} onChange={(e) => setTendered(e.target.value)} className="input-field mt-1" />
                </label>
              ) : (
                <label className="text-xs text-text-secondary">Reference / TrxID
                  <input value={reference} onChange={(e) => setReference(e.target.value)} maxLength={100} className="input-field mt-1" />
                </label>
              )}
            </div>
            {method === "gift_card" && (
              <div className="text-xs text-text-secondary flex items-center gap-3">
                <button
                  type="button"
                  className="text-primary-fg"
                  disabled={!giftCode}
                  onClick={async () => {
                    try {
                      const card = await lookupGiftCard(giftCode);
                      setGiftInfo(card.usable ? `Balance ${formatBDT(card.balance)}` : (card.problem ?? "Can't be used"));
                    } catch (err) {
                      setGiftInfo(err instanceof ApiError ? err.message : "Couldn't check that card.");
                    }
                  }}
                >
                  Check balance
                </button>
                {giftInfo && <span className="text-strong">{giftInfo}</span>}
                <span>Leave Amount empty to use as much as the card holds. No tip from a gift card.</span>
              </div>
            )}
            {method === "cash" && tendered !== "" && (
              <div className="text-sm text-strong">Change to give: <strong>{formatBDT(changeDue(payAmount, tipValue, Number(tendered)))}</strong></div>
            )}
            <button disabled={busy || (method === "cash" && !shift) || (method === "gift_card" && !giftCode)} className="btn-primary w-full py-2.5">
              {busy ? "Saving..." : `Take ${formatBDT(payAmount)}${tipValue ? ` + ${formatBDT(tipValue)} tip` : ""}`}
            </button>
          </form>
        </Can>
      )}

      {!closed && due > 0 && (
        <Can permission="pos.access">
          <OnlinePayment orderId={order.id} due={due} onSettled={() => void refresh().then(onChanged)} />
        </Can>
      )}

      {!closed && summary?.paid === 0 && <DiscountBox order={order} run={run} />}

      {(summary?.net ?? 0) > 0 && (
        <Can permission="orders.refund">
          <RefundBox order={order} net={summary?.net ?? 0} run={run} />
        </Can>
      )}
    </div>
  );
}

function Stat({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className="bg-surface-hover rounded-lg p-2">
      <div className="text-[11px] text-text-secondary">{label}</div>
      <div className={strong ? "font-bold text-strong" : "text-strong"}>{value}</div>
    </div>
  );
}

type Run = (fn: () => Promise<unknown>, okText: string) => Promise<void>;

function DiscountBox({ order, run }: { order: Order; run: Run }) {
  const [type, setType] = useState<"percent" | "fixed">("percent");
  const [value, setValue] = useState("");
  const [reason, setReason] = useState("");
  const base = order.subtotal + order.taxTotal - (order.taxInclusiveTotal ?? 0) - (order.discountTotal - (order.manualDiscountTotal ?? 0));
  const preview = type === "percent" ? percentOf(base, Number(value) || 0) : Number(value) || 0;

  return (
    <Can permission="discounts.apply">
      <div className="border-t border-border pt-4 space-y-2">
        <div className="text-xs font-medium text-text-secondary">Discount{(order.manualDiscountTotal ?? 0) > 0 && ` · currently ${formatBDT(order.manualDiscountTotal ?? 0)} (${order.discountReason})`}</div>
        <div className="flex gap-2">
          <select aria-label="Discount type" value={type} onChange={(e) => setType(e.target.value as "percent" | "fixed")} className="input-field w-28 text-sm">
            <option value="percent">%</option>
            <option value="fixed">৳ fixed</option>
          </select>
          <input type="number" min={0} step="0.01" value={value} onChange={(e) => setValue(e.target.value)} className="input-field w-24 text-sm" placeholder="Value" />
          <input value={reason} onChange={(e) => setReason(e.target.value)} className="input-field flex-1 text-sm" placeholder="Reason (required)" maxLength={200} />
        </div>
        <div className="flex items-center justify-between text-xs text-text-secondary">
          <span>{value ? `Takes off ${formatBDT(preview)}` : "The server enforces your discount limit."}</span>
          <span className="space-x-3">
            {(order.manualDiscountTotal ?? 0) > 0 && (
              <button className="text-danger" onClick={() => void run(() => removeDiscount(order.id), "Discount removed.")}>Remove</button>
            )}
            <button className="text-primary-fg" disabled={!value || reason.length < 3} onClick={() => void run(() => applyDiscount(order.id, { type, value: Number(value), reason }), "Discount applied.")}>
              Apply
            </button>
          </span>
        </div>
      </div>
    </Can>
  );
}

function RefundBox({ order, net, run }: { order: Order; net: number; run: Run }) {
  const [open, setOpen] = useState(false);
  const [amount, setAmount] = useState(String(net));
  const [method, setMethod] = useState<PaymentMethod>("card");
  const [reason, setReason] = useState("");
  const [restock, setRestock] = useState(false);
  const full = Number(amount) === net;

  if (!open) {
    return (
      <div className="border-t border-border pt-4">
        <button className="text-sm text-danger" onClick={() => setOpen(true)}>Refund…</button>
      </div>
    );
  }
  return (
    <div className="border-t border-border pt-4 space-y-2">
      <div className="text-xs font-medium text-text-secondary">Refund (up to {formatBDT(net)})</div>
      <div className="grid sm:grid-cols-3 gap-2">
        <input type="number" min={0.01} max={net} step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} className="input-field text-sm" />
        <select value={method} onChange={(e) => setMethod(e.target.value as PaymentMethod)} className="input-field text-sm">
          {METHODS.filter((m) => m.id !== "gift_card").map((m) => <option key={m.id} value={m.id}>{m.label}</option>)}
        </select>
        <input value={reason} onChange={(e) => setReason(e.target.value)} className="input-field text-sm" placeholder="Reason (required)" maxLength={200} />
      </div>
      <label className="flex items-center gap-2 text-xs text-text-secondary">
        <input type="checkbox" checked={restock} disabled={!full} onChange={(e) => setRestock(e.target.checked)} />
        Put ingredient stock back (full refunds only)
      </label>
      <div className="flex gap-3 justify-end">
        <button className="text-xs text-text-secondary" onClick={() => setOpen(false)}>Cancel</button>
        <button
          className="btn-secondary text-danger text-sm"
          disabled={reason.length < 3 || !Number(amount)}
          onClick={() => void run(() => refundOrder(order.id, { amount: Number(amount), method, reason, restock: restock && full }), `Refunded ${formatBDT(Number(amount))} on ${order.code}.`)}
        >
          Refund
        </button>
      </div>
    </div>
  );
}
