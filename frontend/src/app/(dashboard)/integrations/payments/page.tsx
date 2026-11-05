"use client";

import { useState } from "react";
import { usePaymentGateways, updatePaymentGateway } from "@/hooks/use-integrations";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";
import type { GatewayField, PaymentGateway } from "@/types/integrations";

// Honest, per-gateway note about how far each integration has actually been proven.
const CONFIDENCE: Record<string, string> = {
  bkash: "Built from bKash's public docs. Test in their sandbox before going live.",
  sslcommerz: "Built from SSLCommerz's public docs. Test in their sandbox before going live.",
  aamarpay: "Built from aamarPay's public docs. Test in their sandbox before going live.",
  piprapay: "Lower confidence — check the field names against your Pipra Pay version.",
  nagad: "Medium-low confidence — Nagad's encrypted/signed requests must be verified in their sandbox.",
  eps: "Lowest confidence — verify every field against EPS's current merchant docs first.",
  bangla_qr: "Shows a standard QR for the customer; nothing confirms payment automatically — staff confirm it by hand.",
};

export default function PaymentGatewaysPage() {
  const { gateways, isLoading, error, refresh } = usePaymentGateways();
  const [configuring, setConfiguring] = useState<string | null>(null);

  return (
    <section className="max-w-3xl mx-auto space-y-5">
      <div>
        <h2 className="text-2xl font-bold text-strong">Payment Gateways</h2>
        <p className="text-sm text-text-secondary">
          Enabled gateways appear as &ldquo;Pay online&rdquo; on an order. Money is added to an order only after the provider confirms it to our server for the exact amount.
        </p>
      </div>

      <div className="bg-warning/10 border border-warning/30 text-warning text-xs rounded-lg px-3 py-2">
        These integrations have not been run against live provider accounts. Use each provider&apos;s sandbox and a small real payment before relying on one. Refunds are
        recorded here but the money is returned from the provider&apos;s own dashboard.
      </div>

      <div className="card overflow-hidden">
        {error ? (
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load payment gateways.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className="h-14 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : (
          gateways.map((gw) => (
            <div key={gw.id} className="p-4 border-b border-border last:border-b-0">
              <div className="flex items-center justify-between gap-3">
                <div>
                  <div className="text-strong font-medium">{gw.displayName}</div>
                  <div className="text-xs text-text-secondary">{gw.isConfigured ? "Credentials saved" : "Not configured"}</div>
                  {CONFIDENCE[gw.gatewayKey] && <div className="text-[11px] text-text-muted mt-0.5">{CONFIDENCE[gw.gatewayKey]}</div>}
                </div>
                <Can permission="integrations.manage">
                  <div className="flex items-center gap-3 shrink-0">
                    <button className="text-primary-fg text-xs font-medium" onClick={() => setConfiguring(configuring === gw.id ? null : gw.id)}>
                      {gw.isConfigured ? "Edit credentials" : "Configure"}
                    </button>
                    <label className="flex items-center gap-2 text-xs text-text-secondary cursor-pointer">
                      <input
                        type="checkbox"
                        checked={gw.isEnabled}
                        onChange={async (e) => {
                          try {
                            await updatePaymentGateway(gw.id, { is_enabled: e.target.checked });
                            await refresh();
                          } catch (err) {
                            window.alert(err instanceof ApiError ? err.message : "Couldn't update this gateway.");
                          }
                        }}
                      />
                      Enabled
                    </label>
                  </div>
                </Can>
              </div>

              {configuring === gw.id && (
                <ConfigForm
                  gateway={gw}
                  onClose={() => setConfiguring(null)}
                  onSaved={() => {
                    setConfiguring(null);
                    void refresh();
                  }}
                />
              )}
            </div>
          ))
        )}
      </div>
    </section>
  );
}

function ConfigForm({ gateway, onClose, onSaved }: { gateway: PaymentGateway; onClose: () => void; onSaved: () => void }) {
  const fields = gateway.fields ?? [];
  const [values, setValues] = useState<Record<string, string>>(() => Object.fromEntries(fields.map((f) => [f.key, f.secret ? "" : (f.value ?? "")])));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      // Blank secret fields are left out so the stored secret is kept.
      await updatePaymentGateway(gateway.id, { config: values });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't save these credentials.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="mt-3 pt-3 border-t border-border space-y-2">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-xs rounded-lg px-2 py-1.5">{error}</div>}
      {fields.map((f) => (
        <FieldInput key={f.key} field={f} value={values[f.key] ?? ""} onChange={(v) => setValues((cur) => ({ ...cur, [f.key]: v }))} />
      ))}
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary text-xs py-1.5 px-3">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary text-xs py-1.5 px-3">
          {saving ? "Saving..." : "Save credentials"}
        </button>
      </div>
    </form>
  );
}

function FieldInput({ field, value, onChange }: { field: GatewayField; value: string; onChange: (v: string) => void }) {
  if (field.type === "bool") {
    const on = ["1", "true", "on", "yes"].includes(value.toLowerCase());
    return (
      <label className="flex items-center gap-2 text-xs text-text-secondary">
        <input type="checkbox" checked={on} onChange={(e) => onChange(e.target.checked ? "1" : "0")} />
        {field.label}
      </label>
    );
  }
  const hint = field.secret && field.isSet ? "Saved — leave blank to keep" : field.required ? "Required" : "Optional";
  return (
    <label className="block text-xs text-text-secondary">
      {field.label}
      {field.type === "textarea" ? (
        <textarea value={value} onChange={(e) => onChange(e.target.value)} rows={4} autoComplete="off" spellCheck={false} className="input-field mt-1 text-sm font-mono" placeholder={hint} />
      ) : (
        <input
          type={field.secret ? "password" : "text"}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          autoComplete="off"
          className="input-field mt-1 text-sm"
          placeholder={hint}
        />
      )}
    </label>
  );
}
