"use client";

import { useEffect, useState } from "react";
import { useSiteSettings, updateSiteSettings, useMedia } from "@/hooks/use-cms";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";

export default function ThemeSettingsPage() {
  const { settings, isLoading, refresh } = useSiteSettings();
  const { media } = useMedia();
  const [form, setForm] = useState({
    site_name: "",
    tagline: "",
    primary_color: "#FF0000",
    contact_phone: "",
    contact_email: "",
    social_facebook: "",
    social_instagram: "",
    logo_media_id: "",
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    if (settings) {
      setForm({
        site_name: settings.siteName,
        tagline: settings.tagline ?? "",
        primary_color: settings.primaryColor,
        contact_phone: settings.contactPhone ?? "",
        contact_email: settings.contactEmail ?? "",
        social_facebook: settings.socialFacebook ?? "",
        social_instagram: settings.socialInstagram ?? "",
        logo_media_id: settings.logoMediaId ?? "",
      });
    }
  }, [settings]);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    setSaved(false);
    try {
      await updateSiteSettings({
        ...form,
        logo_media_id: form.logo_media_id || undefined,
      });
      setSaved(true);
      refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't save these settings.");
    } finally {
      setSaving(false);
    }
  }

  if (isLoading) {
    return <div className="max-w-2xl mx-auto h-96 bg-surface-hover rounded-card animate-pulse" />;
  }

  return (
    <section className="max-w-2xl mx-auto space-y-5">
      <div>
        <h2 className="text-2xl font-bold text-strong">Theme & Site Settings</h2>
        <p className="text-sm text-text-secondary">Your public website&apos;s branding and contact details.</p>
      </div>

      <form onSubmit={handleSubmit} className="card p-5 space-y-4">
        {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
        {saved && <div className="bg-success/10 border border-success/30 text-success text-sm rounded-lg px-3 py-2">Settings saved.</div>}

        <div className="grid sm:grid-cols-2 gap-3">
          <div>
            <label className="block text-xs font-medium text-text-secondary mb-1.5">Site name</label>
            <input aria-label="Site name" value={form.site_name} onChange={(e) => setForm({ ...form, site_name: e.target.value })} className="input-field" />
          </div>
          <div>
            <label className="block text-xs font-medium text-text-secondary mb-1.5">Tagline</label>
            <input aria-label="Tagline" value={form.tagline} onChange={(e) => setForm({ ...form, tagline: e.target.value })} className="input-field" />
          </div>
          <div>
            <label className="block text-xs font-medium text-text-secondary mb-1.5">Primary color</label>
            <div className="flex items-center gap-2">
              <input
                type="color"
                aria-label="Primary color picker"
                value={form.primary_color}
                onChange={(e) => setForm({ ...form, primary_color: e.target.value })}
                className="w-10 h-10 rounded border border-border bg-transparent"
              />
              <input
                aria-label="Primary color hex"
                value={form.primary_color}
                onChange={(e) => setForm({ ...form, primary_color: e.target.value })}
                className="input-field font-mono"
              />
            </div>
          </div>
          <div>
            <label className="block text-xs font-medium text-text-secondary mb-1.5">Logo</label>
            <select aria-label="Logo" value={form.logo_media_id} onChange={(e) => setForm({ ...form, logo_media_id: e.target.value })} className="input-field">
              <option value="">No logo selected</option>
              {media.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.originalFilename}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-xs font-medium text-text-secondary mb-1.5">Contact phone</label>
            <input aria-label="Contact phone" value={form.contact_phone} onChange={(e) => setForm({ ...form, contact_phone: e.target.value })} className="input-field" />
          </div>
          <div>
            <label className="block text-xs font-medium text-text-secondary mb-1.5">Contact email</label>
            <input aria-label="Contact email"
              type="email"
              value={form.contact_email}
              onChange={(e) => setForm({ ...form, contact_email: e.target.value })}
              className="input-field"
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-text-secondary mb-1.5">Facebook URL</label>
            <input aria-label="Facebook URL"
              value={form.social_facebook}
              onChange={(e) => setForm({ ...form, social_facebook: e.target.value })}
              className="input-field"
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-text-secondary mb-1.5">Instagram URL</label>
            <input aria-label="Instagram URL"
              value={form.social_instagram}
              onChange={(e) => setForm({ ...form, social_instagram: e.target.value })}
              className="input-field"
            />
          </div>
        </div>

        <Can permission="cms.manage">
          <div className="flex justify-end">
            <button type="submit" disabled={saving} className="btn-primary">
              {saving ? "Saving..." : "Save settings"}
            </button>
          </div>
        </Can>
      </form>
    </section>
  );
}
