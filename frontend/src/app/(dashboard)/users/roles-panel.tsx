"use client";

import { useMemo, useState } from "react";
import { createRole, deleteRole, updateRole, usePermissions, useRoles } from "@/hooks/use-admin";
import { ApiError } from "@/lib/api/client";
import { firstFieldErrors, groupPermissions, toggleInList } from "@/lib/admin-utils";
import type { AdminRole, PermissionItem } from "@/types/admin";

function describe(err: unknown, fallback: string): string {
  if (!(err instanceof ApiError)) return fallback;
  const fields = firstFieldErrors(err.fieldErrors);
  return fields.length > 0 ? fields.join(" ") : err.message;
}

export function RolesPanel() {
  const { roles, isLoading, error, refresh } = useRoles();
  const { permissions, isLoading: permsLoading, error: permsError } = usePermissions();
  const [editing, setEditing] = useState<AdminRole | "new" | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  async function handleDelete(role: AdminRole) {
    if (!window.confirm(`Delete the “${role.name}” role?`)) return;
    setActionError(null);
    try {
      await deleteRole(role.id);
      refresh();
    } catch (err) {
      setActionError(describe(err, "Couldn’t delete this role."));
    }
  }

  return (
    <div className="space-y-4">
      <div className="card p-4 flex items-center justify-between gap-3">
        <p className="text-sm text-text-secondary">
          A role is a named bundle of permissions. You can only create or edit roles made of permissions you hold
          yourself.
        </p>
        <button className="btn-primary shrink-0" onClick={() => setEditing("new")} disabled={permsLoading || !!permsError}>
          + New role
        </button>
      </div>

      {actionError && (
        <div role="alert" className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">
          {actionError}
        </div>
      )}

      {editing && (
        <RoleForm
          role={editing === "new" ? null : editing}
          permissions={permissions}
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
            {error instanceof ApiError ? error.message : "Couldn’t load roles."}
          </div>
        ) : isLoading ? (
          <div className="p-4 space-y-3" aria-busy="true">
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className="h-12 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : roles.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No roles yet.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-surface-hover text-text-secondary">
                <tr>
                  <th className="text-left p-3 font-medium">Role</th>
                  <th className="text-right p-3 font-medium">Permissions</th>
                  <th className="text-right p-3 font-medium">Users</th>
                  <th className="text-right p-3 font-medium">Actions</th>
                </tr>
              </thead>
              <tbody>
                {roles.map((r) => (
                  <tr key={r.id} className="border-t border-border align-top">
                    <td className="p-3">
                      <div className="text-strong font-medium">
                        {r.name}
                        {r.isSystem && <span className="badge bg-primary/15 text-primary-fg ml-2">Built-in</span>}
                      </div>
                      {r.description && <div className="text-xs text-text-secondary">{r.description}</div>}
                    </td>
                    <td className="p-3 text-right text-text-secondary">{r.permissions.length}</td>
                    <td className="p-3 text-right text-text-secondary">{r.userCount}</td>
                    <td className="p-3 text-right whitespace-nowrap space-x-3">
                      <button className="text-primary-fg text-xs font-medium" onClick={() => setEditing(r)}>
                        {r.editable ? "Edit" : "View"}
                      </button>
                      <button
                        className="text-danger text-xs font-medium disabled:opacity-40"
                        disabled={!r.editable || r.userCount > 0}
                        title={
                          r.isSystem
                            ? "Built-in roles can’t be deleted."
                            : !r.editable
                              ? "This role holds permissions you don’t have."
                              : r.userCount > 0
                                ? "Reassign its users before deleting this role."
                                : undefined
                        }
                        onClick={() => handleDelete(r)}
                      >
                        Delete
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}

function RoleForm({
  role,
  permissions,
  onClose,
  onSaved,
}: {
  role: AdminRole | null;
  permissions: PermissionItem[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const isEdit = role !== null;
  const readOnly = isEdit && !role.editable;

  const [name, setName] = useState(role?.name ?? "");
  const [description, setDescription] = useState(role?.description ?? "");
  const [selected, setSelected] = useState<string[]>(role?.permissions ?? []);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const groups = useMemo(() => groupPermissions(permissions), [permissions]);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (readOnly) return;
    setError(null);
    setSaving(true);
    const payload = { name, description: description.trim() === "" ? null : description, permissions: selected };
    try {
      if (isEdit) await updateRole(role.id, payload);
      else await createRole(payload);
      onSaved();
    } catch (err) {
      setError(describe(err, "Couldn’t save this role."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <form
      onSubmit={handleSubmit}
      className="card p-5 space-y-4"
      aria-label={isEdit ? (readOnly ? "View role" : "Edit role") : "New role"}
    >
      <h3 className="text-sm font-bold text-strong">{isEdit ? (readOnly ? role.name : `Edit ${role.name}`) : "New role"}</h3>
      {readOnly && (
        <p className="text-xs text-text-secondary">
          {role.isSystem
            ? "Built-in role: it always holds every permission and can’t be changed."
            : "This role includes permissions you don’t hold yourself, so you can view it but not change it."}
        </p>
      )}
      {error && (
        <div role="alert" className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">
          {error}
        </div>
      )}

      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label htmlFor="rf-name" className="block text-xs font-medium text-text-secondary mb-1.5">
            Name
          </label>
          <input
            id="rf-name"
            required
            disabled={readOnly}
            value={name}
            onChange={(e) => setName(e.target.value)}
            className="input-field"
          />
        </div>
        <div>
          <label htmlFor="rf-desc" className="block text-xs font-medium text-text-secondary mb-1.5">
            Description
          </label>
          <input
            id="rf-desc"
            disabled={readOnly}
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            className="input-field"
          />
        </div>
      </div>

      <fieldset>
        <legend className="text-xs font-medium text-text-secondary mb-2">
          Permissions <span className="text-text-muted">({selected.length} selected)</span>
        </legend>
        <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {groups.map((g) => (
            <div key={g.module}>
              <div className="text-xs font-semibold uppercase tracking-wide text-text-muted mb-1.5">{g.module}</div>
              <div className="space-y-1">
                {g.items.map((p) => {
                  const checked = selected.includes(p.name);
                  // Keep already-selected permissions togglable off even if not grantable, so they can be removed.
                  const disabled = readOnly || (!p.grantable && !checked);
                  return (
                    <label
                      key={p.name}
                      title={!p.grantable && !checked ? "You don’t hold this permission, so you can’t grant it." : p.description ?? undefined}
                      className={`flex items-center gap-2 text-sm ${disabled ? "text-text-muted" : "text-strong"}`}
                    >
                      <input
                        type="checkbox"
                        checked={checked}
                        disabled={disabled}
                        onChange={() => setSelected((list) => toggleInList(list, p.name))}
                      />
                      {p.name}
                    </label>
                  );
                })}
              </div>
            </div>
          ))}
        </div>
      </fieldset>

      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          {readOnly ? "Close" : "Cancel"}
        </button>
        {!readOnly && (
          <button type="submit" disabled={saving} className="btn-primary">
            {saving ? "Saving…" : isEdit ? "Save changes" : "Create role"}
          </button>
        )}
      </div>
    </form>
  );
}
