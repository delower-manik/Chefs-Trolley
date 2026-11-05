"use client";

import { useState } from "react";
import { Can } from "@/components/ui/can";
import { ApiError } from "@/lib/api/client";
import { formatBDT, formatDateTime } from "@/lib/format";
import { adjustGiftCard, issueGiftCard, useGiftCard, useGiftCards, voidGiftCard } from "@/hooks/use-marketing";
import type { GiftCardStatus, IssueGiftCardPayload } from "@/types/marketing";

const STATUS_STYLE: Record<GiftCardStatus, string> = {
  active: "bg-success/15 text-success",
  used: "bg-surface-hover text-text-secondary",
  expired: "bg-warning/15 text-warning",
  void: "bg-danger/15 text-danger",
};

export default function GiftCardsPage() {
  const [status, setStatus] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const { cards, meta, isLoading, error, refresh } = useGiftCards(status || undefined, search || undefined, page);
  const [issuing, setIssuing] = useState(false);
  const [openId, setOpenId] = useState<string | null>(null);

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-2xl font-bold text-strong">Gift Cards</h2>
          <p className="text-sm text-text-secondary">
            Sold for cash or card, spent at the till like a payment method. Outstanding balance (what you still owe in food):{" "}
            <strong className="text-strong">{formatBDT(meta?.outstandingBalance ?? 0)}</strong>
          </p>
        </div>
        <Can permission="giftcards.issue">
          <button className="btn-primary" onClick={() => setIssuing(true)}>
            + Sell a gift card
          </button>
        </Can>
      </div>

      {issuing && (
        <IssueDialog
          onClose={() => setIssuing(false)}
          onIssued={() => {
            refresh();
          }}
        />
      )}

      <div className="flex flex-wrap gap-3">
        <select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} className="input-field w-40 text-sm" aria-label="Filter by status">
          <option value="">All cards</option>
          <option value="active">Active</option>
          <option value="used">Used up</option>
          <option value="expired">Expired</option>
          <option value="void">Cancelled</option>
        </select>
        <input
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1); }}
          placeholder="Recipient name or last 4 of code"
          className="input-field flex-1 min-w-[14rem] text-sm"
        />
      </div>

      <div className="card overflow-hidden">
        {error ? (
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load gift cards.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">{Array.from({ length: 4 }).map((_, i) => <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />)}</div>
        ) : cards.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No gift cards match.</div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">Card</th>
                <th className="text-left p-3 font-medium">For</th>
                <th className="text-right p-3 font-medium">Value</th>
                <th className="text-right p-3 font-medium">Balance</th>
                <th className="text-left p-3 font-medium">Expires</th>
                <th className="text-left p-3 font-medium">Status</th>
              </tr>
            </thead>
            <tbody>
              {cards.map((c) => (
                <tr key={c.id} className="border-t border-border cursor-pointer hover:bg-surface-hover/50" onClick={() => setOpenId(openId === c.id ? null : c.id)}>
                  <td className="p-3 text-strong font-mono">GC-••••{c.last4}</td>
                  <td className="p-3 text-text-secondary">{c.recipientName ?? c.customerName ?? "—"}</td>
                  <td className="p-3 text-right text-text-secondary">{formatBDT(c.initialValue)}</td>
                  <td className="p-3 text-right text-strong font-semibold">{formatBDT(c.balance)}</td>
                  <td className="p-3 text-text-secondary">{c.expiresAt ? formatDateTime(c.expiresAt) : "Never"}</td>
                  <td className="p-3"><span className={`badge ${STATUS_STYLE[c.status]}`}>{c.status}</span></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {openId && <CardDetail id={openId} onChanged={refresh} />}

      {meta && meta.lastPage > 1 && (
        <div className="flex justify-center gap-3 text-sm">
          <button className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</button>
          <span className="text-text-secondary self-center">Page {meta.currentPage} of {meta.lastPage}</span>
          <button className="btn-secondary" disabled={page >= meta.lastPage} onClick={() => setPage(page + 1)}>Next</button>
        </div>
      )}
    </section>
  );
}

function CardDetail({ id, onChanged }: { id: string; onChanged: () => void }) {
  const { card, refresh } = useGiftCard(id);
  const [error, setError] = useState<string | null>(null);
  if (!card) return <div className="card p-6 animate-pulse h-24" />;

  async function act(fn: () => Promise<unknown>) {
    setError(null);
    try {
      await fn();
      await refresh();
      onChanged();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "That didn't work.");
    }
  }

  return (
    <div className="card p-5 space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="font-bold text-strong">GC-••••{card.last4} history</h3>
        {card.status !== "void" && (
          <Can permission="giftcards.manage">
            <div className="flex gap-3 text-sm">
              <button
                className="text-primary-fg"
                onClick={() => {
                  const amount = Number(window.prompt("Add (+) or remove (−) how much? e.g. 200 or -200"));
                  if (!amount) return;
                  const reason = window.prompt("Reason for this adjustment?");
                  if (reason) void act(() => adjustGiftCard(card.id, amount, reason));
                }}
              >
                Adjust balance
              </button>
              <button
                className="text-danger"
                onClick={() => {
                  if (!window.confirm(`Cancel this card? The remaining ${formatBDT(card.balance)} is forfeited and this can't be undone.`)) return;
                  const reason = window.prompt("Why is it being cancelled?");
                  if (reason) void act(() => voidGiftCard(card.id, reason));
                }}
              >
                Cancel card
              </button>
            </div>
          </Can>
        )}
      </div>
      {error && <div className="text-sm text-danger" role="alert">{error}</div>}
      <ul className="divide-y divide-border text-sm">
        {card.entries.map((e) => (
          <li key={e.id} className="py-2 flex justify-between gap-3">
            <div>
              <span className="text-strong capitalize">{e.kind}</span>
              {e.reason && <span className="text-xs text-text-secondary"> · {e.reason}</span>}
              <div className="text-xs text-text-secondary">{formatDateTime(e.createdAt)}</div>
            </div>
            <div className="text-right">
              <div className={e.amount < 0 ? "text-danger" : "text-success"}>{e.amount < 0 ? "−" : "+"}{formatBDT(Math.abs(e.amount))}</div>
              <div className="text-xs text-text-secondary">balance {formatBDT(e.balanceAfter)}</div>
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}

function IssueDialog({ onClose, onIssued }: { onClose: () => void; onIssued: () => void }) {
  const [value, setValue] = useState("");
  const [method, setMethod] = useState<IssueGiftCardPayload["sold_method"]>("cash");
  const [reference, setReference] = useState("");
  const [recipient, setRecipient] = useState("");
  const [expires, setExpires] = useState("");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [code, setCode] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const res = await issueGiftCard({
        value: Number(value),
        sold_method: method,
        sold_reference: reference || undefined,
        recipient_name: recipient || undefined,
        expires_at: expires ? new Date(`${expires}T23:59:59+06:00`).toISOString() : undefined,
        note: note || undefined,
      });
      setCode(res.code);
      onIssued();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't issue the card.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/60 grid place-items-center z-50 p-4">
      <div className="card p-5 w-full max-w-md space-y-3">
        {code ? (
          <>
            <h3 className="font-bold text-strong">Gift card issued</h3>
            <div className="bg-surface-hover rounded-lg p-4 text-center">
              <div className="text-xs text-text-secondary mb-1">Give this code to the customer — it is shown only now</div>
              <div className="font-mono text-2xl tracking-widest text-strong select-all">{code.replace(/(.{4})/g, "$1 ").trim()}</div>
            </div>
            <div className="flex gap-2 justify-end">
              <button className="btn-secondary" onClick={() => window.print()}>Print</button>
              <button className="btn-primary" onClick={onClose}>Done</button>
            </div>
          </>
        ) : (
          <form onSubmit={submit} className="space-y-3">
            <h3 className="font-bold text-strong">Sell a gift card</h3>
            {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2" role="alert">{error}</div>}
            <label className="text-xs text-text-secondary block">Value (৳)
              <input required type="number" min={1} step="0.01" value={value} onChange={(e) => setValue(e.target.value)} className="input-field mt-1" />
            </label>
            <label className="text-xs text-text-secondary block">Paid by
              <select value={method} onChange={(e) => setMethod(e.target.value as IssueGiftCardPayload["sold_method"])} className="input-field mt-1">
                <option value="cash">Cash (needs an open shift)</option>
                <option value="card">Card</option>
                <option value="mobile_banking">Mobile banking</option>
                <option value="other">Other</option>
              </select>
            </label>
            {method !== "cash" && (
              <label className="text-xs text-text-secondary block">Reference / TrxID
                <input value={reference} onChange={(e) => setReference(e.target.value)} maxLength={100} className="input-field mt-1" />
              </label>
            )}
            <label className="text-xs text-text-secondary block">For (optional)
              <input value={recipient} onChange={(e) => setRecipient(e.target.value)} maxLength={120} className="input-field mt-1" />
            </label>
            <label className="text-xs text-text-secondary block">Expires on (optional)
              <input type="date" value={expires} onChange={(e) => setExpires(e.target.value)} className="input-field mt-1" />
            </label>
            <label className="text-xs text-text-secondary block">Note (optional)
              <input value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} className="input-field mt-1" />
            </label>
            <div className="flex gap-2 justify-end">
              <button type="button" className="btn-secondary" onClick={onClose}>Cancel</button>
              <button className="btn-primary" disabled={busy}>{busy ? "Issuing..." : "Issue card"}</button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
}
