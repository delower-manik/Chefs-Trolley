"use client";

import { useState } from "react";
import { ApiError } from "@/lib/api/client";
import { createPrinter, deletePrinter, updatePrinter, usePrinters } from "@/hooks/use-pos";
import { useStations } from "@/hooks/use-restaurant";
import type { Printer } from "@/types/pos";

export default function PrintersPage() {
  const { printers, isLoading, refresh } = usePrinters();
  const { items: stations } = useStations();
  const [name, setName] = useState("");
  const [connection, setConnection] = useState<Printer["connection"]>("browser");
  const [role, setRole] = useState<Printer["role"]>("receipt");
  const [host, setHost] = useState("");
  const [port, setPort] = useState("9100");
  const [stationId, setStationId] = useState("");
  const [paper, setPaper] = useState<58 | 80>(80);
  const [drawer, setDrawer] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function add(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    try {
      await createPrinter({
        name, connection, role, paper_width: paper, opens_drawer: drawer,
        host: connection === "network" ? host : null, port: connection === "network" ? Number(port) : null,
        kitchen_station_id: role === "kitchen" && stationId ? stationId : null,
      });
      setName(""); setHost("");
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? (err.fieldErrors ? Object.values(err.fieldErrors).flat().join(" ") : err.message) : "Couldn't save this printer.");
    }
  }

  return (
    <section className="max-w-4xl mx-auto space-y-5">
      <div>
        <h2 className="text-2xl font-bold text-strong">Printers</h2>
        <p className="text-sm text-text-secondary">
          Receipt and kitchen-ticket printers (ESC/POS). <strong>Browser</strong> prints through the browser dialog. <strong>Network</strong> printers must have a
          LAN address; a cloud/shared server usually can&apos;t reach your LAN, in which case the ESC/POS file is handed back to print locally.
        </p>
      </div>

      <form onSubmit={add} className="card p-5 space-y-3">
        {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
        <div className="grid sm:grid-cols-4 gap-3">
          <input required value={name} onChange={(e) => setName(e.target.value)} className="input-field" placeholder="Name (Counter)" maxLength={60} />
          <select aria-label="Connection type" value={connection} onChange={(e) => setConnection(e.target.value as Printer["connection"])} className="input-field">
            <option value="browser">Browser print</option><option value="network">Network (ESC/POS)</option><option value="usb">USB (local agent)</option>
          </select>
          <select aria-label="Printer role" value={role} onChange={(e) => setRole(e.target.value as Printer["role"])} className="input-field">
            <option value="receipt">Receipt</option><option value="kitchen">Kitchen ticket</option>
          </select>
          <select aria-label="Paper width" value={paper} onChange={(e) => setPaper(Number(e.target.value) as 58 | 80)} className="input-field"><option value={80}>80 mm</option><option value={58}>58 mm</option></select>
        </div>
        {connection === "network" && (
          <div className="grid sm:grid-cols-2 gap-3">
            <input required value={host} onChange={(e) => setHost(e.target.value)} className="input-field" placeholder="192.168.1.50" />
            <input type="number" min={1} max={65535} value={port} onChange={(e) => setPort(e.target.value)} className="input-field" placeholder="9100" />
          </div>
        )}
        {role === "kitchen" && stations.length > 0 && (
          <select aria-label="Kitchen station" value={stationId} onChange={(e) => setStationId(e.target.value)} className="input-field">
            <option value="">All stations</option>
            {stations.map((s) => <option key={s.id} value={s.id}>{s.name} only</option>)}
          </select>
        )}
        {role === "receipt" && <label className="flex items-center gap-2 text-sm text-text-secondary"><input type="checkbox" checked={drawer} onChange={(e) => setDrawer(e.target.checked)} /> Kick the cash drawer on cash receipts</label>}
        <div className="flex justify-end"><button className="btn-primary">Add printer</button></div>
      </form>

      {isLoading ? <div className="h-24 bg-surface-hover rounded-card animate-pulse" /> : (
        <ul className="card divide-y divide-border">
          {printers.map((p) => (
            <li key={p.id} className="p-4 flex items-center justify-between">
              <div>
                <div className="text-strong font-medium">{p.name}</div>
                <div className="text-xs text-text-secondary capitalize">{p.role} · {p.connection}{p.host ? ` ${p.host}:${p.port}` : ""} · {p.paperWidth}mm{p.opensDrawer ? " · drawer" : ""}</div>
              </div>
              <div className="flex gap-3 items-center">
                <span className={`badge ${p.isActive ? "bg-success/15 text-success" : "bg-surface-hover text-text-secondary"}`}>{p.isActive ? "Active" : "Off"}</span>
                <button className="text-xs text-text-secondary" onClick={async () => { await updatePrinter(p.id, { is_active: !p.isActive }); await refresh(); }}>{p.isActive ? "Disable" : "Enable"}</button>
                <button className="text-xs text-danger" onClick={async () => { if (window.confirm(`Delete ${p.name}?`)) { await deletePrinter(p.id); await refresh(); } }}>Delete</button>
              </div>
            </li>
          ))}
          {printers.length === 0 && <li className="p-8 text-center text-sm text-text-muted">No printers yet. Browser printing works without any setup from the order&apos;s Receipt page.</li>}
        </ul>
      )}
    </section>
  );
}
