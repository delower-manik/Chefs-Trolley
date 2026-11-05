"use client";

import { useEffect, useMemo, useState } from "react";
import { ApiError } from "@/lib/api/client";
import { saveSettings, useSettings } from "@/hooks/use-settings";
import { useT } from "@/i18n/i18n-provider";
import { useSettingsContext } from "@/lib/settings/settings-provider";
import type { MessageKey } from "@/i18n/en";

type Form = Record<string, string | number | boolean>;
const FEATURES = ["gift_cards", "campaigns", "reservations", "wifi"] as const;

const flatten = (g: Record<string, Record<string, unknown>>): Form => {
  const out: Form = {};
  for (const [group, vals] of Object.entries(g)) for (const [k, v] of Object.entries(vals)) out[`${group}.${k}`] = (v ?? "") as string | number | boolean;
  return out;
};

export default function SettingsPage() {
  const t = useT();
  const { values, mutate } = useSettings();
  const { refresh } = useSettingsContext();
  const [form, setForm] = useState<Form>({});
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const zones = useMemo(() => {
    try {
      return (Intl as unknown as { supportedValuesOf: (k: string) => string[] }).supportedValuesOf("timeZone");
    } catch {
      return ["Asia/Dhaka", "UTC"];
    }
  }, []);

  useEffect(() => { if (values) setForm(flatten(values)); }, [values]);

  const set = (k: string, v: string | number | boolean) => setForm((f) => ({ ...f, [k]: v }));
  const text = (k: string, label: MessageKey, opts: { type?: string; max?: number } = {}) => (
    <label className="block">
      <span className="text-xs text-text-secondary">{t(label)}</span>
      <input className="input-field mt-1 w-full" type={opts.type ?? "text"} maxLength={opts.max} value={String(form[k] ?? "")} onChange={(e) => set(k, e.target.value)} />
    </label>
  );
  const select = (k: string, label: MessageKey, options: { value: string; label: string }[]) => (
    <label className="block">
      <span className="text-xs text-text-secondary">{t(label)}</span>
      <select className="input-field mt-1 w-full" value={String(form[k] ?? "")} onChange={(e) => set(k, e.target.value)}>
        {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
    </label>
  );

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setMsg(null);
    try {
      const body: Form = { ...form, "currency.decimals": Number(form["currency.decimals"]), "web.min_order": Number(form["web.min_order"] || 0) };
      await saveSettings(body);
      await mutate();
      refresh();
      setMsg({ ok: true, text: t("common.saved") });
    } catch (err) {
      setMsg({ ok: false, text: err instanceof ApiError ? err.message : t("common.couldntSave") });
    } finally {
      setSaving(false);
    }
  }

  if (!values) return <p className="text-text-secondary">{t("common.loading")}</p>;

  const langs = [{ value: "en", label: "English" }, { value: "bn", label: "বাংলা" }];

  return (
    <form onSubmit={onSubmit} className="max-w-3xl mx-auto space-y-5">
      <div>
        <h2 className="text-2xl font-bold text-strong">{t("settings.title")}</h2>
        <p className="text-sm text-text-secondary">{t("settings.subtitle")}</p>
      </div>

      <section className="card p-5 space-y-3">
        <h3 className="font-bold text-strong">{t("settings.business")}</h3>
        <div className="grid sm:grid-cols-2 gap-3">
          {text("business.name", "settings.name", { max: 120 })}
          {text("business.legal_name", "settings.legalName", { max: 160 })}
          {text("business.phone", "settings.phone", { max: 40 })}
          {text("business.email", "settings.email", { type: "email", max: 160 })}
          {text("business.tax_id", "settings.taxId", { max: 60 })}
        </div>
        {text("business.address", "settings.address", { max: 255 })}
      </section>

      <section className="card p-5 space-y-3">
        <h3 className="font-bold text-strong">{t("settings.regional")}</h3>
        <div className="grid sm:grid-cols-2 gap-3">
          {select("locale.default", "settings.defaultLanguage", langs)}
          {select("locale.timezone", "settings.timezone", zones.map((z) => ({ value: z, label: z })))}
          {text("currency.code", "settings.currencyCode", { max: 3 })}
          {text("currency.symbol", "settings.currencySymbol", { max: 6 })}
          {select("currency.decimals", "settings.decimals", [0, 1, 2].map((n) => ({ value: String(n), label: String(n) })))}
        </div>
        <p className="text-xs text-text-muted">{t("settings.currencyNote")}</p>
      </section>

      <section className="card p-5 space-y-3">
        <h3 className="font-bold text-strong">{t("settings.receipts")}</h3>
        {select("receipt.language", "settings.receiptLanguage", langs)}
        {text("receipt.header", "settings.receiptHeader", { max: 120 })}
        {text("receipt.footer", "settings.receiptFooter", { max: 255 })}
        <label className="flex items-center gap-2 text-sm text-text-primary">
          <input type="checkbox" checked={!!form["receipt.show_tax_id"]} onChange={(e) => set("receipt.show_tax_id", e.target.checked)} />
          {t("settings.showTaxId")}
        </label>
        <p className="text-xs text-text-muted">{t("settings.thermalNote")}</p>
      </section>

      <section className="card p-5 space-y-3">
        <h3 className="font-bold text-strong">{t("settings.features")}</h3>
        <p className="text-xs text-text-muted">{t("settings.featuresNote")}</p>
        {FEATURES.map((f) => (
          <label key={f} className="flex items-center justify-between gap-3 text-sm text-text-primary">
            {t(`settings.feature.${f}` as MessageKey)}
            <input type="checkbox" role="switch" checked={!!form[`features.${f}`]} onChange={(e) => set(`features.${f}`, e.target.checked)} />
          </label>
        ))}
      </section>

      <section className="card p-5 space-y-3">
        <h3 className="font-bold text-strong">{t("settings.website")}</h3>
        <p className="text-xs text-text-muted">{t("settings.websiteNote")}</p>
        {(["web.ordering", "web.cod", "web.pickup"] as const).map((k) => (
          <label key={k} className="flex items-center justify-between gap-3 text-sm text-text-primary">
            {t(`settings.${k}` as MessageKey)}
            <input type="checkbox" role="switch" checked={!!form[k]} onChange={(e) => set(k, e.target.checked)} />
          </label>
        ))}
        {text("web.min_order", "settings.web.min_order", { type: "number", max: 6 })}
      </section>

      {msg && <div role="alert" className={`text-xs rounded-lg px-3 py-2 border ${msg.ok ? "bg-success/10 border-success/30 text-success" : "bg-danger/10 border-danger/30 text-danger"}`}>{msg.text}</div>}
      <button className="btn-primary" disabled={saving}>{t("common.save")}</button>
    </form>
  );
}
