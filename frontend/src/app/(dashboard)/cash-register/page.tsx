"use client";

import { useState } from "react";
import { ApiError } from "@/lib/api/client";
import { base64ToBytes, varianceOf } from "@/lib/pos-utils";
import { cashMovement, closeShift, getShiftReport, openDrawer, openShift, useCurrentShift, useShifts } from "@/hooks/use-pos";
import { formatBDT, formatDateTime } from "@/lib/format";
import type { CashShift } from "@/types/pos";

export default function CashRegisterPage() {
  const { shift, isLoading, refresh } = useCurrentShift();
  const { shifts, refresh: refreshShifts } = useShifts();
  const [report, setReport] = useState<CashShift | null>(null);
  const [msg, setMsg] = useState<string | null>(null);

  async function run(fn: () => Promise<unknown>) {
    setMsg(null);
    try {
      await fn();
      await Promise.all([refresh(), refreshShifts()]);
    } catch (err) {
      setMsg(err instanceof ApiError ? err.message : "That didn't work. Try again.");
    }
  }

  async function kickDrawer() {
    setMsg(null);
    try {
      const res = await openDrawer();
      const blob = new Blob([base64ToBytes(res.data.escposBase64) as BlobPart], { type: "application/octet-stream" });
      const a = document.createElement("a");
      a.href = URL.createObjectURL(blob);
      a.download = "open-drawer.escpos";
      a.click();
      URL.revokeObjectURL(a.href);
      setMsg("Drawer pulse recorded. Send the downloaded ESC/POS file with your local print agent (the server can't reach a LAN drawer on shared hosting).");
    } catch (err) {
      setMsg(err instanceof ApiError ? err.message : "Couldn't open the drawer.");
    }
  }

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div>
        <h2 className="text-2xl font-bold text-strong">Cash Register</h2>
        <p className="text-sm text-text-secondary">Open a shift with your starting float, take cash on orders, record pay-ins/outs, then count the drawer to close.</p>
      </div>
      {msg && <div className="bg-warning/10 border border-warning/30 text-warning text-sm rounded-lg px-3 py-2" role="alert">{msg}</div>}

      {isLoading ? <div className="h-40 bg-surface-hover rounded-card animate-pulse" /> : shift ? (
        <OpenShift shift={shift} run={run} onDrawer={kickDrawer} />
      ) : (
        <OpenForm run={run} />
      )}

      <div className="card overflow-x-auto">
        <div className="p-4 border-b border-border font-bold text-strong">Recent shifts</div>
        <table className="w-full text-sm">
          <thead><tr className="text-left text-xs text-text-secondary"><th className="p-3">Opened</th><th className="p-3">Status</th><th className="p-3 text-right">Expected</th><th className="p-3 text-right">Counted</th><th className="p-3 text-right">Variance</th><th className="p-3" /></tr></thead>
          <tbody>
            {shifts.map((s) => (
              <tr key={s.id} className="border-t border-border">
                <td className="p-3 text-strong">{formatDateTime(s.openedAt)}</td>
                <td className="p-3 capitalize text-text-secondary">{s.status}</td>
                <td className="p-3 text-right">{s.expectedCash === null ? "—" : formatBDT(s.expectedCash)}</td>
                <td className="p-3 text-right">{s.countedCash === null ? "—" : formatBDT(s.countedCash)}</td>
                <td className={`p-3 text-right ${s.variance ? "text-danger" : "text-text-secondary"}`}>{s.variance === null ? "—" : formatBDT(s.variance)}</td>
                <td className="p-3 text-right"><button className="text-xs text-primary-fg" onClick={() => void getShiftReport(s.id).then((r) => setReport(r.data))}>Z-report</button></td>
              </tr>
            ))}
            {shifts.length === 0 && <tr><td colSpan={6} className="p-6 text-center text-text-muted">No shifts yet.</td></tr>}
          </tbody>
        </table>
      </div>

      {report && <ZReport report={report} onClose={() => setReport(null)} />}
    </section>
  );
}

function OpenForm({ run }: { run: (fn: () => Promise<unknown>) => Promise<void> }) {
  const [float, setFloat] = useState("");
  return (
    <form className="card p-5 flex gap-3 items-end" onSubmit={(e) => { e.preventDefault(); void run(() => openShift(Number(float))); }}>
      <label className="flex-1 text-xs text-text-secondary">Opening float (BDT)
        <input required type="number" min={0} step="0.01" value={float} onChange={(e) => setFloat(e.target.value)} className="input-field mt-1" />
      </label>
      <button className="btn-primary">Open shift</button>
    </form>
  );
}

function OpenShift({ shift, run, onDrawer }: { shift: CashShift; run: (fn: () => Promise<unknown>) => Promise<void>; onDrawer: () => void }) {
  const [mType, setMType] = useState<"in" | "out">("out");
  const [mAmount, setMAmount] = useState("");
  const [mReason, setMReason] = useState("");
  const [counted, setCounted] = useState("");
  const expected = shift.expectedNow ?? shift.openingFloat;

  return (
    <div className="grid md:grid-cols-2 gap-4">
      <div className="card p-5 space-y-3">
        <div className="flex justify-between items-center">
          <h3 className="font-bold text-strong">Shift open</h3>
          <button className="text-xs text-primary-fg" onClick={onDrawer}>Open drawer</button>
        </div>
        <div className="text-sm text-text-secondary">Since {formatDateTime(shift.openedAt)} · float {formatBDT(shift.openingFloat)}</div>
        <div className="text-2xl font-bold text-strong">{formatBDT(expected)} <span className="text-xs font-normal text-text-secondary">should be in the drawer</span></div>
        <ul className="text-sm divide-y divide-border">
          {shift.byMethod?.map((m) => <li key={m.method} className="py-1.5 flex justify-between"><span className="capitalize text-text-secondary">{m.method.replace("_", " ")} · {m.count}</span><span className="text-strong">{formatBDT(m.net)}{m.tips > 0 && ` (+${formatBDT(m.tips)} tips)`}</span></li>)}
        </ul>
      </div>

      <div className="space-y-4">
        <form className="card p-5 space-y-2" onSubmit={(e) => { e.preventDefault(); void run(async () => { await cashMovement(shift.id, { type: mType, amount: Number(mAmount), reason: mReason }); setMAmount(""); setMReason(""); }); }}>
          <h3 className="font-bold text-strong">Cash in / out</h3>
          <div className="flex gap-2">
            <select value={mType} onChange={(e) => setMType(e.target.value as "in" | "out")} className="input-field w-24 text-sm"><option value="out">Out</option><option value="in">In</option></select>
            <input required type="number" min={0.01} step="0.01" value={mAmount} onChange={(e) => setMAmount(e.target.value)} className="input-field w-28 text-sm" placeholder="Amount" />
            <input required minLength={3} value={mReason} onChange={(e) => setMReason(e.target.value)} className="input-field flex-1 text-sm" placeholder="Reason" maxLength={200} />
          </div>
          <button className="btn-secondary text-sm">Record</button>
        </form>

        <form className="card p-5 space-y-2" onSubmit={(e) => { e.preventDefault(); if (window.confirm("Close this shift? The count is final.")) void run(() => closeShift(shift.id, { counted_cash: Number(counted) })); }}>
          <h3 className="font-bold text-strong">Close shift</h3>
          <input required type="number" min={0} step="0.01" value={counted} onChange={(e) => setCounted(e.target.value)} className="input-field text-sm" placeholder="Cash counted in drawer" />
          {counted !== "" && <div className={`text-sm ${varianceOf(Number(counted), expected) === 0 ? "text-success" : "text-warning"}`}>Variance: {formatBDT(varianceOf(Number(counted), expected))}</div>}
          <button className="btn-primary text-sm">Count &amp; close</button>
        </form>
      </div>

      {shift.movements && shift.movements.length > 0 && (
        <div className="card p-4 md:col-span-2">
          <div className="font-bold text-strong mb-2 text-sm">Movements</div>
          {shift.movements.map((m) => <div key={m.id} className="flex justify-between text-sm text-text-secondary"><span>{m.type === "in" ? "In" : "Out"} · {m.reason}</span><span>{formatBDT(m.amount)}</span></div>)}
        </div>
      )}
    </div>
  );
}

function ZReport({ report, onClose }: { report: CashShift; onClose: () => void }) {
  return (
    <div className="fixed inset-0 bg-black/60 grid place-items-center z-50 p-4" onClick={onClose}>
      <div className="card p-5 w-full max-w-md max-h-[85vh] overflow-y-auto space-y-3" onClick={(e) => e.stopPropagation()}>
        <h3 className="font-bold text-strong">Z-report</h3>
        <div className="text-sm text-text-secondary">{formatDateTime(report.openedAt)} → {report.closedAt ? formatDateTime(report.closedAt) : "open"}</div>
        <Row l="Opening float" r={formatBDT(report.openingFloat)} />
        {report.byMethod?.map((m) => (
          <div key={m.method} className="text-sm">
            <Row l={`${m.method.replace("_", " ")} sales (${m.count})`} r={formatBDT(m.payments)} />
            {m.tips > 0 && <Row l="  tips" r={formatBDT(m.tips)} />}
            {m.refunds > 0 && <Row l="  refunds" r={`-${formatBDT(m.refunds)}`} />}
          </div>
        ))}
        {report.movements?.map((m) => <Row key={m.id} l={`Cash ${m.type} · ${m.reason}`} r={`${m.type === "in" ? "+" : "-"}${formatBDT(m.amount)}`} />)}
        <Row l="Expected in drawer" r={formatBDT(report.expectedCash ?? report.expectedNow ?? 0)} strong />
        {report.countedCash !== null && <Row l="Counted" r={formatBDT(report.countedCash)} />}
        {report.variance !== null && <Row l="Variance" r={formatBDT(report.variance)} strong />}
        {!!report.voidedPayments && <div className="text-xs text-text-secondary">{report.voidedPayments} voided payment(s) excluded.</div>}
        <div className="flex gap-2 justify-end"><button className="btn-secondary" onClick={() => window.print()}>Print</button><button className="btn-primary" onClick={onClose}>Close</button></div>
      </div>
    </div>
  );
}

function Row({ l, r, strong }: { l: string; r: string; strong?: boolean }) {
  return <div className={`flex justify-between text-sm capitalize-first ${strong ? "font-bold text-strong" : "text-text-secondary"}`}><span>{l}</span><span>{r}</span></div>;
}
