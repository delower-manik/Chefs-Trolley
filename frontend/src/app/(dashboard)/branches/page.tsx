"use client";

import { useMemo, useState } from "react";
import { createBranch, updateBranch, useBranches } from "@/hooks/use-admin";
import { ApiError } from "@/lib/api/client";
import { firstFieldErrors } from "@/lib/admin-utils";
import type { Branch } from "@/types/admin";

function describe(err: unknown, fallback: string): string {
  if (!(err instanceof ApiError)) return fallback;
  const fields = firstFieldErrors(err.fieldErrors);
  return fields.length > 0 ? fields.join(" ") : err.message;
}

/** IANA zones the browser knows about; a short fallback list if the runtime lacks Intl.supportedValuesOf. */
function timezoneOptions(): string[] {
  const intl = Intl as unknown as { supportedValuesOf?: (key: string) => string[] };
  try {
    const zones = intl.supportedValuesOf?.("timeZone");
    if (zones && zones.length > 0) return zones;
  } catch {
    // fall through to the fallback list
  }
  return ["Asia/Dhaka", "Asia/Kolkata", "Asia/Dubai", "Asia/Singapore", "Europe/London", "America/New_York", "UTC"];
}

export default function BranchesPage() {
  const { branches, isLoading, error, refresh } = useBranches();
  const [editing, setEditing] = useState<Branch | "new" | null>(null);

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div className="flex items-center justify-between gap-3">
        <div>
          <h2 className="text-2xl font-bold text-strong">Branches / Outlets</h2>
          <p className="text-sm text-text-secondary">
            Each branch keeps its own orders, stock and reports, in its own timezone.
          </p>
        </div>
        <button className="btn-primary shrink-0" onClick={() => setEditing("new")}>
          + New branch
        </button>
      </div>

      {editing && (
        <BranchForm
          branch={editing === "new" ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            refresh();
          }}
        />
      )}

      <div className="card overflow-hidden">
        {error ? (
          <div role="alert" className="p-10 text-center text-sm text-text-secondary">
            {error instanceof ApiError ? error.message : "Couldn’t load branches."}
          </div>
        ) : isLoading ? (
          <div className="p-4 space-y-3" aria-busy="true">
            {Array.from({ length: 3 }).map((_, i) => (
              <div key={i} className="h-12 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : branches.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No branches yet.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-surface-hover text-text-secondary">
                <tr>
                  <th className="text-left p-3 font-medium">Branch</th>
                  <th className="text-left p-3 font-medium">Code</th>
                  <th className="text-left p-3 font-medium">Timezone</th>
                  <th className="text-left p-3 font-medium">Contact</th>
                  <th className="text-right p-3 font-medium">Staff</th>
                  <th className="text-right p-3 font-medium">Actions</th>
                </tr>
              </thead>
              <tbody>
                {branches.map((b) => (
                  <tr key={b.id} className="border-t border-border align-top">
                    <td className="p-3">
                      <div className="text-strong font-medium">{b.name}</div>
                      {b.address && <div className="text-xs text-text-secondary">{b.address}</div>}
                    </td>
                    <td className="p-3 text-text-secondary">{b.code}</td>
                    <td className="p-3 text-text-secondary">{b.timezone}</td>
                    <td className="p-3 text-text-secondary">{b.phone ?? "—"}</td>
                    <td className="p-3 text-right text-text-secondary">{b.userCount}</td>
                    <td className="p-3 text-right">
                      <button className="text-primary-fg text-xs font-medium" onClick={() => setEditing(b)}>
                        Edit
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </section>
  );
}

function BranchForm({ branch, onClose, onSaved }: { branch: Branch | null; onClose: () => void; onSaved: () => void }) {
  const isEdit = branch !== null;
  const zones = useMemo(timezoneOptions, []);

  const [name, setName] = useState(branch?.name ?? "");
  const [code, setCode] = useState(branch?.code ?? "");
  const [address, setAddress] = useState(branch?.address ?? "");
  const [phone, setPhone] = useState(branch?.phone ?? "");
  const [timezone, setTimezone] = useState(branch?.timezone ?? "Asia/Dhaka");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSaving(true);
    const payload = {
      name,
      code: code.trim().toUpperCase(),
      address: address.trim() === "" ? null : address,
      phone: phone.trim() === "" ? null : phone,
      timezone,
    };
    try {
      if (isEdit) await updateBranch(branch.id, payload);
      else await createBranch(payload);
      onSaved();
    } catch (err) {
      setError(describe(err, "Couldn’t save this branch."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-4" aria-label={isEdit ? "Edit branch" : "New branch"}>
      <h3 className="text-sm font-bold text-strong">{isEdit ? `Edit ${branch.name}` : "New branch"}</h3>
      {error && (
        <div role="alert" className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">
          {error}
        </div>
      )}
      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label htmlFor="bf-name" className="block text-xs font-medium text-text-secondary mb-1.5">
            Name
          </label>
          <input id="bf-name" required value={name} onChange={(e) => setName(e.target.value)} className="input-field" />
        </div>
        <div>
          <label htmlFor="bf-code" className="block text-xs font-medium text-text-secondary mb-1.5">
            Code
          </label>
          <input
            id="bf-code"
            required
            maxLength={20}
            value={code}
            onChange={(e) => setCode(e.target.value.toUpperCase())}
            className="input-field"
          />
          <p className="text-xs text-text-muted mt-1">Capital letters, numbers and dashes, e.g. DHK-01.</p>
        </div>
        <div className="sm:col-span-2">
          <label htmlFor="bf-address" className="block text-xs font-medium text-text-secondary mb-1.5">
            Address
          </label>
          <input id="bf-address" value={address} onChange={(e) => setAddress(e.target.value)} className="input-field" />
        </div>
        <div>
          <label htmlFor="bf-phone" className="block text-xs font-medium text-text-secondary mb-1.5">
            Phone
          </label>
          <input id="bf-phone" value={phone} onChange={(e) => setPhone(e.target.value)} className="input-field" />
        </div>
        <div>
          <label htmlFor="bf-tz" className="block text-xs font-medium text-text-secondary mb-1.5">
            Timezone
          </label>
          <input
            id="bf-tz"
            required
            list="bf-tz-options"
            value={timezone}
            onChange={(e) => setTimezone(e.target.value)}
            className="input-field"
          />
          <datalist id="bf-tz-options">
            {zones.map((z) => (
              <option key={z} value={z} />
            ))}
          </datalist>
          <p className="text-xs text-text-muted mt-1">Decides which calendar day a sale belongs to in reports.</p>
        </div>
      </div>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving…" : isEdit ? "Save changes" : "Create branch"}
        </button>
      </div>
    </form>
  );
}
