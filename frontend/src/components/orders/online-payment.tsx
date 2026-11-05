"use client";

import { useEffect, useRef, useState } from "react";
import QRCode from "qrcode";
import { ApiError } from "@/lib/api/client";
import { cancelGatewayPayment, checkGatewayPayment, confirmGatewayPayment, startGatewayPayment, useGatewayOptions, useGatewaySession } from "@/hooks/use-gateway";
import { formatBDT } from "@/lib/format";

/** "Pay online": bKash, Nagad, SSLCommerz, EPS, aamarPay, Pipra Pay (hosted pages) and Bangla QR (scan + confirm by hand). */
export function OnlinePayment({ orderId, due, onSettled }: { orderId: string; due: number; onSettled: () => void }) {
  const options = useGatewayOptions();
  const [gateway, setGateway] = useState("");
  const [amount, setAmount] = useState("");
  const [sessionId, setSessionId] = useState<string | null>(null);
  const [trx, setTrx] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const { session, refresh } = useGatewaySession(sessionId);
  const notified = useRef(false);

  useEffect(() => {
    if (session?.status === "paid" && !notified.current) {
      notified.current = true;
      onSettled();
    }
  }, [session?.status, onSettled]);

  if (options.length === 0) return null;

  async function act(fn: () => Promise<unknown>) {
    setBusy(true);
    setError(null);
    try {
      await fn();
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "That didn't work. Try again.");
    } finally {
      setBusy(false);
    }
  }

  const open = session && ["created", "pending"].includes(session.status);
  const manual = options.find((o) => o.key === session?.gateway)?.manual;

  return (
    <div className="border-t border-border pt-4 space-y-3">
      <div className="text-xs font-medium text-text-secondary">Pay online</div>
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-xs rounded-lg px-3 py-2" role="alert">{error}</div>}

      {!session || !open ? (
        <>
          {session && <Outcome status={session.status} note={session.note} amount={session.amount} />}
          <div className="flex flex-wrap gap-2">
            {options.map((o) => (
              <button key={o.key} type="button" onClick={() => setGateway(o.key)} className={`px-3 py-1.5 rounded-full text-sm ${gateway === o.key ? "bg-primary text-on-primary" : "bg-surface-hover text-text-secondary"}`}>
                {o.label}
              </button>
            ))}
          </div>
          <div className="flex gap-2 items-center">
            <input type="number" step="0.01" min={0.01} max={due} placeholder={`Amount (${due})`} value={amount} onChange={(e) => setAmount(e.target.value)} className="input-field w-40 text-sm" />
            <button
              type="button"
              className="btn-primary text-sm"
              disabled={busy || !gateway}
              onClick={() =>
                act(async () => {
                  notified.current = false;
                  const s = await startGatewayPayment(orderId, gateway, amount === "" ? undefined : Number(amount));
                  setSessionId(s.id);
                })
              }
            >
              {busy ? "Starting..." : "Start online payment"}
            </button>
          </div>
        </>
      ) : (
        <div className="space-y-3 text-sm">
          <div className="text-strong">
            {formatBDT(session.amount)} via {options.find((o) => o.key === session.gateway)?.label ?? session.gateway} — waiting for payment…
          </div>
          {session.redirectUrl && (
            <div className="space-y-2">
              <a href={session.redirectUrl} target="_blank" rel="noopener noreferrer" className="btn-primary inline-block text-sm">
                Open payment page
              </a>
              <QrBox value={session.redirectUrl} caption="…or let the customer scan this to pay on their phone" />
            </div>
          )}
          {session.qrPayload && <QrBox value={session.qrPayload} caption="Customer scans with any bank or MFS app" />}
          {manual && (
            <div className="flex gap-2">
              <input value={trx} onChange={(e) => setTrx(e.target.value)} className="input-field text-sm flex-1" placeholder="TrxID from the bank / app message" maxLength={60} />
              <button type="button" className="btn-secondary text-sm" disabled={busy || trx.length < 4} onClick={() => act(() => confirmGatewayPayment(session.id, trx))}>
                Confirm received
              </button>
            </div>
          )}
          {manual && <p className="text-[11px] text-warning">Nothing verifies this automatically — confirm only after you see the money in your merchant app or SMS.</p>}
          <div className="flex gap-3">
            {!manual && (
              <button type="button" className="text-xs text-primary-fg" disabled={busy} onClick={() => act(() => checkGatewayPayment(session.id))}>
                Check now
              </button>
            )}
            <button type="button" className="text-xs text-text-secondary" disabled={busy} onClick={() => act(() => cancelGatewayPayment(session.id))}>
              Cancel
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

function Outcome({ status, note, amount }: { status: string; note: string | null; amount: number }) {
  const tone = status === "paid" ? "bg-success/10 border-success/30 text-success" : status === "review" ? "bg-warning/10 border-warning/30 text-warning" : "bg-surface-hover border-border text-text-secondary";
  const text =
    status === "paid" ? `Online payment of ${formatBDT(amount)} received.` :
    status === "review" ? "Money may have arrived but was NOT added to the order — a manager must check it with the provider." :
    `Last online payment ${status}.`;
  return (
    <div className={`text-xs rounded-lg px-3 py-2 border ${tone}`} role="status">
      {text}
      {note && <div className="mt-1 opacity-80">{note}</div>}
    </div>
  );
}

function QrBox({ value, caption }: { value: string; caption: string }) {
  const [src, setSrc] = useState<string | null>(null);
  useEffect(() => {
    let live = true;
    QRCode.toDataURL(value, { margin: 1, width: 192, errorCorrectionLevel: "M" })
      .then((u) => live && setSrc(u))
      .catch(() => live && setSrc(null));
    return () => {
      live = false;
    };
  }, [value]);
  if (!src) return null;
  return (
    <div className="inline-block bg-white p-2 rounded-lg text-center">
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img src={src} alt="Payment QR code" width={192} height={192} />
      <div className="text-[10px] text-black/70 max-w-[192px]">{caption}</div>
    </div>
  );
}
