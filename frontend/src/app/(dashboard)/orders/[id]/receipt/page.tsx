"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ApiError } from "@/lib/api/client";
import { fetchReceipt, printOrder, usePrinters } from "@/hooks/use-pos";
import { base64ToBytes } from "@/lib/pos-utils";
import type { ReceiptDoc } from "@/types/pos";

export default function ReceiptPage() {
  const { id } = useParams<{ id: string }>();
  const [doc, setDoc] = useState<ReceiptDoc | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [note, setNote] = useState<string | null>(null);
  const { printers } = usePrinters();

  useEffect(() => {
    fetchReceipt(id).then((r) => setDoc(r.data)).catch((e) => setError(e instanceof ApiError ? e.message : "Couldn't load the receipt."));
  }, [id]);

  async function sendTo(printerId: string, type: "receipt" | "kitchen", copy: boolean) {
    setNote(null);
    try {
      const res = (await printOrder(id, { printer_id: printerId, type, copy })).data;
      if (res.delivery === "server") {
        setNote("Sent to the printer.");
        return;
      }
      if (res.escposBase64) {
        const blob = new Blob([base64ToBytes(res.escposBase64) as BlobPart], { type: "application/octet-stream" });
        const a = document.createElement("a");
        a.href = URL.createObjectURL(blob);
        a.download = `${doc?.code ?? "order"}-${type}.escpos`;
        a.click();
        URL.revokeObjectURL(a.href);
      }
      setNote(`${res.note ? res.note + " " : ""}ESC/POS file downloaded — send it with your local print agent, or use browser print.`);
    } catch (e) {
      setNote(e instanceof ApiError ? e.message : "Couldn't print.");
    }
  }

  if (error) return <div className="card p-8 text-center text-sm text-text-secondary max-w-md mx-auto mt-12">{error}</div>;
  if (!doc) return <div className="h-64 bg-surface-hover rounded-card animate-pulse max-w-md mx-auto" />;

  const taxToAdd = doc.tax - doc.taxIncluded;
  const L = (k: string, fallback: string) => (typeof doc.labels?.[k] === "string" ? (doc.labels[k] as string) : fallback);
  const methodLabel = (m: string) => {
    const methods = doc.labels?.methods;
    return (methods && typeof methods === "object" && methods[m]) || m.replace("_", " ");
  };
  // Receipts always show two decimals and use the receipt's own language, whatever the screen is set to.
  const intl = doc.locale === "bn" ? "bn-BD" : "en-BD";
  const money = (n: number) => `${doc.currencySymbol ?? "৳"}${new Intl.NumberFormat(intl, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n)}`;

  return (
    <section className="max-w-3xl mx-auto grid md:grid-cols-[1fr_260px] gap-5">
      <div id="receipt" lang={doc.locale ?? "en"} className="bg-white text-black rounded-lg p-5 font-mono text-xs w-full max-w-[340px] mx-auto">
        <div className="text-center font-bold text-base">{doc.business ?? "Chefs Trolley"}</div>
        {doc.legalName && doc.legalName !== doc.business && <div className="text-center">{doc.legalName}</div>}
        {doc.address && <div className="text-center">{doc.address}</div>}
        {doc.phone && <div className="text-center">{L("phone", "Phone")}: {doc.phone}</div>}
        {doc.taxId && <div className="text-center">{L("tax_id", "BIN")}: {doc.taxId}</div>}
        {doc.header && <div className="text-center">{doc.header}</div>}
        <div className="text-center">{L("order", "Order")} {doc.code}</div>
        <div className="text-center">{doc.date}{doc.table ? ` · ${L("table", "Table")} ${doc.table}` : ""}</div>
        <hr className="my-2 border-black" />
        {doc.items.map((i, k) => (
          <div key={k}>
            <div className="flex justify-between"><span>{i.quantity} × {i.name}</span><span>{money(i.total)}</span></div>
            {i.modifiers.map((m) => <div key={m} className="pl-3">+ {m}</div>)}
          </div>
        ))}
        <hr className="my-2 border-black" />
        <Line l={L("subtotal", "Subtotal")} r={money(doc.subtotal)} />
        {doc.discount > 0 && <Line l={L("discount", "Discount")} r={`-${money(doc.discount)}`} />}
        {taxToAdd > 0 && <Line l={L("tax", "Tax")} r={money(taxToAdd)} />}
        {doc.deliveryFee > 0 && <Line l={L("delivery", "Delivery")} r={money(doc.deliveryFee)} />}
        <div className="flex justify-between font-bold text-sm"><span>{L("total", "Total").toUpperCase()}</span><span>{money(doc.grandTotal)}</span></div>
        {doc.taxIncluded > 0 && <Line l={`(${L("incl_tax", "incl. tax")})`} r={money(doc.taxIncluded)} />}
        {doc.payments.map((p, k) => (
          <div key={k}>
            <Line l={`${L("paid", "Paid")} ${methodLabel(p.method)}`} r={money(p.amount)} />
            {p.tip > 0 && <Line l={`  ${L("tip", "Tip")}`} r={money(p.tip)} />}
            {!!p.change && <Line l={`  ${L("change", "Change")}`} r={money(p.change)} />}
          </div>
        ))}
        {doc.refunded > 0 && <Line l={L("refunded", "Refunded")} r={money(doc.refunded)} />}
        {doc.balanceDue > 0 && <div className="flex justify-between font-bold"><span>{L("balance_due", "Balance due").toUpperCase()}</span><span>{money(doc.balanceDue)}</span></div>}
        <hr className="my-2 border-black" />
        <div className="text-center">{doc.footer}</div>
      </div>

      <div className="space-y-3 print:hidden">
        <Link href={`/orders/${id}`} className="text-sm text-primary-fg">← Back to order</Link>
        <button className="btn-primary w-full" onClick={() => window.print()}>Print in browser</button>
        <div className="card p-3 space-y-2">
          <div className="text-xs font-medium text-text-secondary">Receipt printers</div>
          {printers.filter((p) => p.role === "receipt" && p.isActive).map((p) => (
            <div key={p.id} className="flex items-center justify-between text-sm">
              <span className="text-strong">{p.name}</span>
              <span className="space-x-2">
                <button className="text-xs text-primary-fg" onClick={() => void sendTo(p.id, "receipt", false)}>Print</button>
                <button className="text-xs text-text-secondary" onClick={() => void sendTo(p.id, "receipt", true)}>Reprint copy</button>
              </span>
            </div>
          ))}
          <div className="text-xs font-medium text-text-secondary pt-2">Kitchen printers</div>
          {printers.filter((p) => p.role === "kitchen" && p.isActive).map((p) => (
            <div key={p.id} className="flex items-center justify-between text-sm">
              <span className="text-strong">{p.name}</span>
              <button className="text-xs text-primary-fg" onClick={() => void sendTo(p.id, "kitchen", false)}>Print ticket</button>
            </div>
          ))}
          {printers.length === 0 && <p className="text-xs text-text-muted">No printers set up yet — add them under Printers.</p>}
          {note && <p className="text-xs text-text-secondary">{note}</p>}
        </div>
        <p className="text-[11px] text-text-muted">Thermal printouts are English-only for now (Bengali text needs bitmap rendering, planned later).</p>
      </div>

      <style>{`@media print { body * { visibility: hidden; } #receipt, #receipt * { visibility: visible; } #receipt { position: absolute; left: 0; top: 0; width: 80mm; max-width: none; } }`}</style>
    </section>
  );
}

function Line({ l, r }: { l: string; r: string }) {
  return <div className="flex justify-between"><span>{l}</span><span>{r}</span></div>;
}
