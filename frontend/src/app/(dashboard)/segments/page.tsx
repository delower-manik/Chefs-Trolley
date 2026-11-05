"use client";

import { useEffect, useState } from "react";
import { Can } from "@/components/ui/can";
import { ApiError } from "@/lib/api/client";
import { createSegment, deleteSegment, previewSegment, updateSegment, useSegments } from "@/hooks/use-marketing";
import type { RuleOp, Segment, SegmentField, SegmentPreview, SegmentRule } from "@/types/marketing";

const OP_LABEL: Record<RuleOp, string> = { gte: "at least", lte: "at most", eq: "exactly" };

export default function SegmentsPage() {
  const { segments, fields, isLoading, error, refresh } = useSegments();
  const [editing, setEditing] = useState<Segment | "new" | null>(null);

  const label = (key: string) => fields.find((f) => f.key === key)?.label ?? key;

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div className="flex items-center justify-between gap-3">
        <div>
          <h2 className="text-2xl font-bold text-strong">Customer Segments</h2>
          <p className="text-sm text-text-secondary">Saved groups of customers (big spenders, lapsed regulars…) that campaigns can target. Counts update live.</p>
        </div>
        <Can permission="campaigns.manage">
          <button className="btn-primary" onClick={() => setEditing("new")}>+ New segment</button>
        </Can>
      </div>

      {editing && (
        <SegmentEditor
          segment={editing === "new" ? null : editing}
          fields={fields}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            void refresh();
          }}
        />
      )}

      <div className="card overflow-hidden">
        {error ? (
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load segments.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">{Array.from({ length: 3 }).map((_, i) => <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />)}</div>
        ) : segments.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No segments yet. A campaign with no segment goes to every customer who hasn&apos;t opted out.</div>
        ) : (
          <ul className="divide-y divide-border">
            {segments.map((s) => (
              <li key={s.id} className="p-4 flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="text-strong font-semibold">{s.name} <span className="text-text-secondary font-normal">· {s.size} customers</span></div>
                  {s.description && <div className="text-xs text-text-secondary">{s.description}</div>}
                  <div className="text-xs text-text-secondary mt-1">
                    {s.rules.length === 0
                      ? "Everyone"
                      : s.rules.map((r) => `${label(r.field)} ${typeof r.value === "boolean" || r.op === "eq" && (r.value === "true" || r.value === "false") ? (String(r.value) === "true" ? "yes" : "no") : `${OP_LABEL[r.op]} ${r.value}`}`).join(s.match === "all" ? " AND " : " OR ")}
                  </div>
                </div>
                <Can permission="campaigns.manage">
                  <div className="flex gap-3 text-sm">
                    <button className="text-primary-fg" onClick={() => setEditing(s)}>Edit</button>
                    <button
                      className="text-danger"
                      onClick={async () => {
                        if (!window.confirm(`Delete segment "${s.name}"? Campaigns that used it are kept.`)) return;
                        await deleteSegment(s.id);
                        void refresh();
                      }}
                    >
                      Delete
                    </button>
                  </div>
                </Can>
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  );
}

function defaultRule(field: SegmentField | undefined): SegmentRule {
  return field?.type === "bool" ? { field: field.key, op: "eq", value: true } : { field: field?.key ?? "total_spent", op: "gte", value: 0 };
}

function SegmentEditor({ segment, fields, onClose, onSaved }: { segment: Segment | null; fields: SegmentField[]; onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState(segment?.name ?? "");
  const [description, setDescription] = useState(segment?.description ?? "");
  const [match, setMatch] = useState<"all" | "any">(segment?.match ?? "all");
  const [rules, setRules] = useState<SegmentRule[]>(segment?.rules ?? []);
  const [preview, setPreview] = useState<SegmentPreview | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Live preview, debounced, so people see the size before saving.
  useEffect(() => {
    const t = setTimeout(() => {
      previewSegment(match, rules).then(setPreview).catch(() => setPreview(null));
    }, 400);
    return () => clearTimeout(t);
  }, [match, rules]);

  const typeOf = (key: string) => fields.find((f) => f.key === key)?.type ?? "number";
  const setRule = (i: number, patch: Partial<SegmentRule>) => setRules((cur) => cur.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const body = { name, description: description || undefined, match, rules };
      if (segment) await updateSegment(segment.id, body);
      else await createSegment(body);
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't save the segment.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={save} className="card p-5 space-y-4">
      <h3 className="font-bold text-strong">{segment ? "Edit segment" : "New segment"}</h3>
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2" role="alert">{error}</div>}
      <div className="grid sm:grid-cols-2 gap-3">
        <label className="text-xs text-text-secondary">Name
          <input required value={name} onChange={(e) => setName(e.target.value)} maxLength={120} className="input-field mt-1" />
        </label>
        <label className="text-xs text-text-secondary">Description (optional)
          <input value={description} onChange={(e) => setDescription(e.target.value)} maxLength={255} className="input-field mt-1" />
        </label>
      </div>

      <div className="flex items-center gap-2 text-sm text-text-secondary">
        Customers who match
        <select value={match} onChange={(e) => setMatch(e.target.value as "all" | "any")} className="input-field w-24 py-1">
          <option value="all">all</option>
          <option value="any">any</option>
        </select>
        of these rules:
      </div>

      <div className="space-y-2">
        {rules.map((r, i) => (
          <div key={i} className="flex flex-wrap gap-2 items-center">
            <select
              value={r.field}
              onChange={(e) => setRules((cur) => cur.map((x, idx) => (idx === i ? defaultRule(fields.find((f) => f.key === e.target.value)) : x)))}
              className="input-field w-56 text-sm"
              aria-label="Field"
            >
              {fields.map((f) => <option key={f.key} value={f.key}>{f.label}</option>)}
            </select>
            {typeOf(r.field) === "bool" ? (
              <select value={String(r.value)} onChange={(e) => setRule(i, { op: "eq", value: e.target.value === "true" })} className="input-field w-24 text-sm" aria-label="Value">
                <option value="true">yes</option>
                <option value="false">no</option>
              </select>
            ) : (
              <>
                <select value={r.op} onChange={(e) => setRule(i, { op: e.target.value as RuleOp })} className="input-field w-28 text-sm" aria-label="Operator">
                  {(Object.keys(OP_LABEL) as RuleOp[]).map((op) => <option key={op} value={op}>{OP_LABEL[op]}</option>)}
                </select>
                <input type="number" min={0} step="any" value={String(r.value)} onChange={(e) => setRule(i, { value: e.target.value === "" ? 0 : Number(e.target.value) })} className="input-field w-28 text-sm" aria-label="Number" />
              </>
            )}
            <button type="button" className="text-danger text-sm" onClick={() => setRules((cur) => cur.filter((_, idx) => idx !== i))}>Remove</button>
          </div>
        ))}
        <button type="button" className="text-primary-fg text-sm" disabled={rules.length >= 12 || fields.length === 0} onClick={() => setRules((cur) => [...cur, defaultRule(fields[0])])}>
          + Add a rule
        </button>
        {rules.length === 0 && <div className="text-xs text-text-secondary">No rules = every active customer.</div>}
      </div>

      <div className="bg-surface-hover rounded-lg p-3 text-sm">
        {preview ? (
          <>
            <div className="text-strong"><strong>{preview.matching}</strong> customers match{preview.optedOut > 0 && <span className="text-text-secondary"> ({preview.optedOut} opted out of marketing and will never be messaged)</span>}</div>
            <div className="text-xs text-text-secondary mt-1">Reachable: {preview.reachable.sms} by SMS · {preview.reachable.email} by email · {preview.reachable.push} with the app</div>
            {preview.sample.length > 0 && <div className="text-xs text-text-secondary mt-1">e.g. {preview.sample.map((s) => s.name).join(", ")}</div>}
          </>
        ) : (
          <span className="text-text-secondary">Calculating…</span>
        )}
      </div>

      <div className="flex gap-2 justify-end">
        <button type="button" className="btn-secondary" onClick={onClose}>Cancel</button>
        <button className="btn-primary" disabled={busy || !name.trim()}>{busy ? "Saving..." : "Save segment"}</button>
      </div>
    </form>
  );
}
