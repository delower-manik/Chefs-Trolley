"use client";

import { useState } from "react";
import { useWifiZones, createWifiZone, deleteWifiZone, useWifiVouchers, generateWifiVouchers } from "@/hooks/use-integrations";
import { formatDateTime } from "@/lib/format";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";
import type { WifiAccessType } from "@/types/integrations";

export default function WifiZonesPage() {
  const { zones, isLoading, error, refresh } = useWifiZones();
  const [formOpen, setFormOpen] = useState(false);
  const [selectedZoneId, setSelectedZoneId] = useState<string | null>(null);

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Free Wi-Fi Zones</h2>
          <p className="text-sm text-text-secondary">Zone and voucher management — not a live router connection.</p>
        </div>
        <Can permission="integrations.manage">
          <button className="btn-primary" onClick={() => setFormOpen(true)}>
            + New zone
          </button>
        </Can>
      </div>

      <div className="bg-warning/10 border border-warning/30 text-warning text-xs rounded-lg px-3 py-2">
        This tracks zones and voucher codes only. Actually granting network access still needs real router/captive-portal
        infrastructure — a browser can&apos;t detect or control nearby Wi-Fi devices on its own.
      </div>

      {formOpen && (
        <NewZoneForm
          onClose={() => setFormOpen(false)}
          onSaved={() => {
            setFormOpen(false);
            refresh();
          }}
        />
      )}

      <div className="grid lg:grid-cols-2 gap-5">
        <div className="card overflow-hidden">
          {error ? (
            <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load Wi-Fi zones.</div>
          ) : isLoading ? (
            <div className="p-4 space-y-3">
              {Array.from({ length: 3 }).map((_, i) => (
                <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
              ))}
            </div>
          ) : zones.length === 0 ? (
            <div className="p-10 text-center text-sm text-text-secondary">No Wi-Fi zones set up yet.</div>
          ) : (
            <table className="w-full text-sm">
              <thead className="bg-surface-hover text-text-secondary">
                <tr>
                  <th className="text-left p-3 font-medium">Zone</th>
                  <th className="text-left p-3 font-medium">Access</th>
                  <th className="text-right p-3 font-medium">Actions</th>
                </tr>
              </thead>
              <tbody>
                {zones.map((zone) => (
                  <tr
                    key={zone.id}
                    className={`border-t border-border cursor-pointer hover:bg-surface-hover/50 ${selectedZoneId === zone.id ? "bg-surface-hover" : ""}`}
                    onClick={() => setSelectedZoneId(zone.id)}
                  >
                    <td className="p-3">
                      <div className="text-strong font-medium">{zone.name}</div>
                      {zone.ssid && <div className="text-xs text-text-secondary">{zone.ssid}</div>}
                    </td>
                    <td className="p-3 text-text-secondary capitalize">{zone.accessType.replace("_", " ")}</td>
                    <td className="p-3 text-right">
                      <Can permission="integrations.manage">
                        <button
                          className="text-danger text-xs font-medium"
                          onClick={async (e) => {
                            e.stopPropagation();
                            if (!window.confirm(`Delete "${zone.name}"?`)) return;
                            try {
                              await deleteWifiZone(zone.id);
                              if (selectedZoneId === zone.id) setSelectedZoneId(null);
                              refresh();
                            } catch (err) {
                              window.alert(err instanceof ApiError ? err.message : "Couldn't delete this zone.");
                            }
                          }}
                        >
                          Delete
                        </button>
                      </Can>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <div>{selectedZoneId ? <VoucherPanel zoneId={selectedZoneId} /> : <EmptyVoucherState />}</div>
      </div>
    </section>
  );
}

function EmptyVoucherState() {
  return <div className="card p-8 text-center text-sm text-text-secondary">Select a zone to manage its vouchers.</div>;
}

function VoucherPanel({ zoneId }: { zoneId: string }) {
  const { vouchers, isLoading, refresh } = useWifiVouchers(zoneId);
  const [duration, setDuration] = useState("60");
  const [count, setCount] = useState("1");
  const [generating, setGenerating] = useState(false);

  async function handleGenerate() {
    setGenerating(true);
    try {
      await generateWifiVouchers(zoneId, { duration_minutes: Number(duration), count: Number(count) });
      refresh();
    } catch (err) {
      window.alert(err instanceof ApiError ? err.message : "Couldn't generate vouchers.");
    } finally {
      setGenerating(false);
    }
  }

  return (
    <div className="card p-4 space-y-4">
      <Can permission="integrations.manage">
        <div className="flex gap-2 items-end">
          <div className="flex-1">
            <label className="block text-xs font-medium text-text-secondary mb-1">Minutes</label>
            <input aria-label="Minutes" type="number" min={1} value={duration} onChange={(e) => setDuration(e.target.value)} className="input-field text-sm" />
          </div>
          <div className="flex-1">
            <label className="block text-xs font-medium text-text-secondary mb-1">Count</label>
            <input aria-label="Count" type="number" min={1} max={50} value={count} onChange={(e) => setCount(e.target.value)} className="input-field text-sm" />
          </div>
          <button disabled={generating} onClick={handleGenerate} className="btn-primary text-xs py-2 px-3">
            Generate
          </button>
        </div>
      </Can>

      <div className="space-y-1.5 max-h-80 overflow-y-auto">
        {isLoading ? (
          <div className="h-24 bg-surface-hover rounded animate-pulse" />
        ) : vouchers.length === 0 ? (
          <p className="text-xs text-text-secondary">No vouchers generated yet.</p>
        ) : (
          vouchers.map((v) => (
            <div key={v.id} className="flex justify-between items-center text-sm border-b border-border pb-1.5">
              <span className="font-mono text-strong">{v.code}</span>
              <span className="text-xs text-text-secondary">{v.durationMinutes}m</span>
              <span className={`badge ${v.isUsed ? "bg-surface-hover text-text-secondary" : "bg-success/15 text-success"}`}>
                {v.isUsed ? `Used ${v.usedAt ? formatDateTime(v.usedAt) : ""}` : "Unused"}
              </span>
            </div>
          ))
        )}
      </div>
    </div>
  );
}

function NewZoneForm({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState("");
  const [ssid, setSsid] = useState("");
  const [accessType, setAccessType] = useState<WifiAccessType>("open");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await createWifiZone({ name, ssid: ssid || undefined, access_type: accessType });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't create this zone.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div className="grid sm:grid-cols-3 gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Zone name</label>
          <input aria-label="Zone name" required value={name} onChange={(e) => setName(e.target.value)} className="input-field" placeholder="Main Dining" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">SSID (optional)</label>
          <input aria-label="SSID (optional)" value={ssid} onChange={(e) => setSsid(e.target.value)} className="input-field" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Access type</label>
          <select aria-label="Access type" value={accessType} onChange={(e) => setAccessType(e.target.value as WifiAccessType)} className="input-field">
            <option value="open">Open</option>
            <option value="voucher">Voucher code</option>
            <option value="time_limited">Time limited</option>
          </select>
        </div>
      </div>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving..." : "Save zone"}
        </button>
      </div>
    </form>
  );
}
