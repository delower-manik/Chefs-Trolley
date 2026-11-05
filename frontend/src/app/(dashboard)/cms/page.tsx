"use client";

import { useState } from "react";
import { useCmsPages, useMedia, createCmsPage, updateCmsPage, deleteCmsPage, publishCmsPage, unpublishCmsPage } from "@/hooks/use-cms";
import { formatDateTime } from "@/lib/format";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";
import type { CmsPage, CmsSection, CmsSectionType, MediaItem } from "@/types/cms";

const SECTION_TYPES: { value: CmsSectionType; label: string }[] = [
  { value: "hero", label: "Hero banner" },
  { value: "text", label: "Text block" },
  { value: "image", label: "Image" },
  { value: "menu_highlight", label: "Menu highlight" },
  { value: "gallery", label: "Gallery" },
  { value: "cta", label: "Call to action" },
];

export default function CmsPagesPage() {
  const { pages, isLoading, error, refresh } = useCmsPages();
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<CmsPage | null>(null);

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Website Pages</h2>
          <p className="text-sm text-text-secondary">Built from structured sections — never raw HTML.</p>
        </div>
        <Can permission="cms.manage">
          <button
            className="btn-primary"
            onClick={() => {
              setEditing(null);
              setFormOpen(true);
            }}
          >
            + New page
          </button>
        </Can>
      </div>

      {formOpen && (
        <PageEditor
          initial={editing}
          onClose={() => setFormOpen(false)}
          onSaved={() => {
            setFormOpen(false);
            refresh();
          }}
        />
      )}

      <div className="card overflow-hidden">
        {error ? (
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load pages.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : pages.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No pages yet.</div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">Title</th>
                <th className="text-left p-3 font-medium">Slug</th>
                <th className="text-left p-3 font-medium">Status</th>
                <th className="text-left p-3 font-medium">Updated</th>
                <th className="text-right p-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {pages.map((page) => (
                <tr key={page.id} className="border-t border-border">
                  <td className="p-3 text-strong font-medium">{page.title}</td>
                  <td className="p-3 text-text-secondary font-mono text-xs">/{page.slug}</td>
                  <td className="p-3">
                    <span className={`badge ${page.isPublished ? "bg-success/15 text-success" : "bg-warning/15 text-warning"}`}>
                      {page.isPublished ? "Published" : "Draft"}
                    </span>
                  </td>
                  <td className="p-3 text-text-secondary">{formatDateTime(page.updatedAt)}</td>
                  <td className="p-3 text-right">
                    <Can permission="cms.manage">
                      <button
                        className="text-primary-fg text-xs font-medium mr-3"
                        onClick={() => {
                          setEditing(page);
                          setFormOpen(true);
                        }}
                      >
                        Edit
                      </button>
                      {page.isPublished ? (
                        <button
                          className="text-warning text-xs font-medium mr-3"
                          onClick={async () => {
                            await unpublishCmsPage(page.id);
                            refresh();
                          }}
                        >
                          Unpublish
                        </button>
                      ) : (
                        <button
                          className="text-success text-xs font-medium mr-3"
                          onClick={async () => {
                            await publishCmsPage(page.id);
                            refresh();
                          }}
                        >
                          Publish
                        </button>
                      )}
                      <button
                        className="text-danger text-xs font-medium"
                        onClick={async () => {
                          if (!window.confirm(`Delete "${page.title}"?`)) return;
                          try {
                            await deleteCmsPage(page.id);
                            refresh();
                          } catch (err) {
                            window.alert(err instanceof ApiError ? err.message : "Couldn't delete this page.");
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
    </section>
  );
}

function PageEditor({ initial, onClose, onSaved }: { initial: CmsPage | null; onClose: () => void; onSaved: () => void }) {
  const [title, setTitle] = useState(initial?.title ?? "");
  const [slug, setSlug] = useState(initial?.slug ?? "");
  const [metaDescription, setMetaDescription] = useState(initial?.metaDescription ?? "");
  const [sections, setSections] = useState<CmsSection[]>(initial?.sections ?? []);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const { media } = useMedia();
  const images = media.filter((m) => m.mimeType.startsWith("image/"));

  function addSection(type: CmsSectionType) {
    setSections((prev) => [...prev, { type, title: "", body: "" }]);
  }
  function updateSectionField(index: number, field: string, value: string) {
    setSections((prev) => prev.map((s, i) => (i === index ? { ...s, [field]: value } : s)));
  }
  function setSection(index: number, patch: Record<string, unknown>) {
    setSections((prev) => prev.map((s, i) => (i === index ? { ...s, ...patch } : s)));
  }
  function removeSection(index: number) {
    setSections((prev) => prev.filter((_, i) => i !== index));
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const payload = { title, slug: slug || undefined, meta_description: metaDescription || undefined, sections };
      if (initial) {
        await updateCmsPage(initial.id, payload);
      } else {
        await createCmsPage(payload);
      }
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't save this page.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-4">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Title</label>
          <input aria-label="Title" required value={title} onChange={(e) => setTitle(e.target.value)} className="input-field" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Slug (optional — derived from title)</label>
          <input aria-label="Slug (optional — derived from title)" value={slug} onChange={(e) => setSlug(e.target.value)} className="input-field font-mono" placeholder="about-us" />
        </div>
      </div>
      <div>
        <label className="block text-xs font-medium text-text-secondary mb-1.5">Meta description (SEO)</label>
        <input aria-label="Meta description (SEO)" value={metaDescription} onChange={(e) => setMetaDescription(e.target.value)} className="input-field" maxLength={300} />
      </div>

      <div>
        <div className="flex items-center justify-between mb-2">
          <span className="text-sm font-bold text-strong">Sections</span>
          <select
            onChange={(e) => {
              if (e.target.value) addSection(e.target.value as CmsSectionType);
              e.target.value = "";
            }}
            className="input-field text-xs w-auto py-1"
            defaultValue=""
          >
            <option value="" disabled>
              + Add section
            </option>
            {SECTION_TYPES.map((t) => (
              <option key={t.value} value={t.value}>
                {t.label}
              </option>
            ))}
          </select>
        </div>

        {sections.length === 0 ? (
          <p className="text-xs text-text-secondary">No sections yet — add one above.</p>
        ) : (
          <div className="space-y-2">
            {sections.map((section, i) => (
              <div key={i} className="border border-border rounded-lg p-3">
                <div className="flex justify-between items-center mb-2">
                  <span className="text-xs font-bold text-primary-fg uppercase">
                    {SECTION_TYPES.find((t) => t.value === section.type)?.label}
                  </span>
                  <button type="button" onClick={() => removeSection(i)} className="text-danger text-xs">
                    Remove
                  </button>
                </div>
                <input
                  value={(section.title as string) ?? ""}
                  onChange={(e) => updateSectionField(i, "title", e.target.value)}
                  className="input-field text-sm mb-2"
                  placeholder="Heading"
                />
                <textarea
                  value={(section.body as string) ?? ""}
                  onChange={(e) => updateSectionField(i, "body", e.target.value)}
                  className="input-field text-sm"
                  rows={2}
                  placeholder="Content"
                />
                {(section.type === "image" || section.type === "hero") && (
                  <MediaPick
                    label={section.type === "hero" ? "Background image (optional)" : "Image"}
                    media={images}
                    selected={section.mediaId ? [section.mediaId as string] : []}
                    single
                    onChange={(ids) => setSection(i, { mediaId: ids[0] ?? undefined })}
                  />
                )}
                {section.type === "gallery" && (
                  <MediaPick
                    label="Gallery images (up to 12)"
                    media={images}
                    selected={(section.mediaIds as string[]) ?? []}
                    onChange={(ids) => setSection(i, { mediaIds: ids.slice(0, 12) })}
                  />
                )}
              </div>
            ))}
          </div>
        )}
      </div>

      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving..." : "Save page"}
        </button>
      </div>
    </form>
  );
}

/** Thumbnail picker over the media library (Content → Media). Single choice for image/hero, several for a gallery. */
function MediaPick({ label, media, selected, single, onChange }: { label: string; media: MediaItem[]; selected: string[]; single?: boolean; onChange: (ids: string[]) => void }) {
  const toggle = (id: string) => onChange(single ? (selected[0] === id ? [] : [id]) : selected.includes(id) ? selected.filter((x) => x !== id) : [...selected, id]);
  return (
    <div className="mt-2">
      <p className="text-xs text-text-secondary mb-1">{label}</p>
      {media.length === 0 ? (
        <p className="text-xs text-text-muted">No images yet — upload some in Content → Media first.</p>
      ) : (
        <div className="grid grid-cols-4 sm:grid-cols-6 gap-2 max-h-40 overflow-y-auto">
          {media.map((m) => (
            <button
              key={m.id}
              type="button"
              onClick={() => toggle(m.id)}
              aria-pressed={selected.includes(m.id)}
              title={m.altText ?? m.originalFilename}
              className={`aspect-square rounded-lg overflow-hidden border-2 ${selected.includes(m.id) ? "border-primary" : "border-transparent"}`}
            >
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={m.url} alt={m.altText ?? ""} className="h-full w-full object-cover" />
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
