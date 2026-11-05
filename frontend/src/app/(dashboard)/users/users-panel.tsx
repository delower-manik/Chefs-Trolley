"use client";

import { useState } from "react";
import {
  createUser,
  deleteUser,
  resetUserPassword,
  updateUser,
  useAssignableOptions,
  useUsers,
} from "@/hooks/use-admin";
import { useDebounced } from "@/hooks/use-debounced";
import { ApiError } from "@/lib/api/client";
import { firstFieldErrors, toggleInList } from "@/lib/admin-utils";
import { Pagination } from "@/components/ui/pagination";
import type { AdminUser, UserFilters } from "@/types/admin";

function describe(err: unknown, fallback: string): string {
  if (!(err instanceof ApiError)) return fallback;
  const fields = firstFieldErrors(err.fieldErrors);
  return fields.length > 0 ? fields.join(" ") : err.message;
}

export function UsersPanel() {
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<NonNullable<UserFilters["status"]>>("");
  const [page, setPage] = useState(1);
  const debouncedSearch = useDebounced(search);
  const { users, meta, isLoading, error, refresh } = useUsers({ q: debouncedSearch, status, page });

  const [editing, setEditing] = useState<AdminUser | "new" | null>(null);
  const [resetting, setResetting] = useState<AdminUser | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  async function run(action: () => Promise<unknown>, fallback: string) {
    setActionError(null);
    try {
      await action();
      refresh();
    } catch (err) {
      setActionError(describe(err, fallback));
    }
  }

  return (
    <div className="space-y-4">
      <div className="card p-4 flex flex-wrap items-end gap-3">
        <div className="grow max-w-xs">
          <label htmlFor="user-search" className="block text-xs font-medium text-text-secondary mb-1.5">
            Search
          </label>
          <input
            id="user-search"
            type="search"
            placeholder="Name or email"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            className="input-field"
          />
        </div>
        <div>
          <label htmlFor="user-status" className="block text-xs font-medium text-text-secondary mb-1.5">
            Status
          </label>
          <select
            id="user-status"
            value={status}
            onChange={(e) => {
              setStatus(e.target.value as typeof status);
              setPage(1);
            }}
            className="input-field"
          >
            <option value="">All</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        <button className="btn-primary ml-auto" onClick={() => setEditing("new")}>
          + New user
        </button>
      </div>

      {actionError && (
        <div role="alert" className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">
          {actionError}
        </div>
      )}

      {editing && (
        <UserForm
          user={editing === "new" ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            refresh();
          }}
        />
      )}

      {resetting && (
        <ResetPasswordForm
          user={resetting}
          onClose={() => setResetting(null)}
          onDone={() => {
            setResetting(null);
            refresh();
          }}
        />
      )}

      <div className="card overflow-hidden">
        {error ? (
          <div role="alert" className="p-10 text-center text-sm text-text-secondary">
            {error instanceof ApiError ? error.message : "Couldn’t load users."}
          </div>
        ) : isLoading ? (
          <div className="p-4 space-y-3" aria-busy="true">
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className="h-12 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : users.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">
            {debouncedSearch || status ? "No users match these filters." : "No users to show yet."}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-surface-hover text-text-secondary">
                <tr>
                  <th className="text-left p-3 font-medium">User</th>
                  <th className="text-left p-3 font-medium">Roles</th>
                  <th className="text-left p-3 font-medium">Branches</th>
                  <th className="text-left p-3 font-medium">Status</th>
                  <th className="text-right p-3 font-medium">Actions</th>
                </tr>
              </thead>
              <tbody>
                {users.map((u) => (
                  <tr key={u.id} className="border-t border-border align-top">
                    <td className="p-3">
                      <div className="text-strong font-medium">
                        {u.name}
                        {u.isSelf && <span className="badge bg-primary/15 text-primary-fg ml-2">You</span>}
                      </div>
                      <div className="text-xs text-text-secondary">{u.email}</div>
                    </td>
                    <td className="p-3">
                      <div className="flex flex-wrap gap-1">
                        {u.roles.length === 0 && <span className="text-text-muted text-xs">None</span>}
                        {u.roles.map((r) => (
                          <span key={r.id} className="badge bg-surface-hover text-text-secondary">
                            {r.name}
                          </span>
                        ))}
                      </div>
                    </td>
                    <td className="p-3 text-text-secondary">{u.branches.map((b) => b.code).join(", ") || "—"}</td>
                    <td className="p-3">
                      <span className={`badge ${u.isActive ? "bg-success/15 text-success" : "bg-surface-hover text-text-secondary"}`}>
                        {u.isActive ? "Active" : "Inactive"}
                      </span>
                    </td>
                    <td className="p-3 text-right whitespace-nowrap space-x-3">
                      <button className="text-primary-fg text-xs font-medium" onClick={() => setEditing(u)}>
                        Edit
                      </button>
                      <button className="text-primary-fg text-xs font-medium" onClick={() => setResetting(u)}>
                        Reset password
                      </button>
                      <button
                        className="text-xs font-medium text-text-secondary disabled:opacity-40"
                        disabled={u.isSelf}
                        title={u.isSelf ? "You can’t deactivate your own account." : undefined}
                        onClick={() =>
                          run(() => updateUser(u.id, { is_active: !u.isActive }), "Couldn’t change this user’s status.")
                        }
                      >
                        {u.isActive ? "Deactivate" : "Activate"}
                      </button>
                      <button
                        className="text-danger text-xs font-medium disabled:opacity-40"
                        disabled={u.isSelf}
                        title={u.isSelf ? "You can’t delete your own account." : undefined}
                        onClick={() => {
                          if (!window.confirm(`Delete ${u.name}? They will lose access immediately.`)) return;
                          run(() => deleteUser(u.id), "Couldn’t delete this user.");
                        }}
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
        {meta && <Pagination page={meta.currentPage} lastPage={meta.lastPage} total={meta.total} onChange={setPage} />}
      </div>
    </div>
  );
}

function UserForm({ user, onClose, onSaved }: { user: AdminUser | null; onClose: () => void; onSaved: () => void }) {
  const { options, isLoading: optionsLoading, error: optionsError } = useAssignableOptions();
  const isEdit = user !== null;
  const isSelf = user?.isSelf ?? false;

  const [name, setName] = useState(user?.name ?? "");
  const [email, setEmail] = useState(user?.email ?? "");
  const [password, setPassword] = useState("");
  const [roleIds, setRoleIds] = useState<string[]>(user?.roles.map((r) => r.id) ?? []);
  const [branchIds, setBranchIds] = useState<string[]>(user?.branches.map((b) => b.id) ?? []);
  const [activeBranchId, setActiveBranchId] = useState<string>(user?.activeBranchId ?? "");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const activeBranchChoices = options?.branches.filter((b) => branchIds.includes(b.id)) ?? [];
  const effectiveActiveBranch = branchIds.includes(activeBranchId) ? activeBranchId : (branchIds[0] ?? "");

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    if (!isSelf && roleIds.length === 0) {
      setError("Choose at least one role.");
      return;
    }
    if (!isSelf && branchIds.length === 0) {
      setError("Choose at least one branch.");
      return;
    }
    setSaving(true);
    try {
      if (!isEdit) {
        await createUser({
          name,
          email,
          password,
          role_ids: roleIds,
          branch_ids: branchIds,
          active_branch_id: effectiveActiveBranch || null,
        });
      } else if (isSelf) {
        // The API refuses self-edits of access; only the profile fields are sent.
        await updateUser(user.id, { name, email });
      } else {
        await updateUser(user.id, {
          name,
          email,
          role_ids: roleIds,
          branch_ids: branchIds,
          active_branch_id: effectiveActiveBranch || null,
        });
      }
      onSaved();
    } catch (err) {
      setError(describe(err, "Couldn’t save this user."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-4" aria-label={isEdit ? "Edit user" : "New user"}>
      <h3 className="text-sm font-bold text-strong">{isEdit ? `Edit ${user.name}` : "New user"}</h3>
      {error && (
        <div role="alert" className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">
          {error}
        </div>
      )}

      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label htmlFor="uf-name" className="block text-xs font-medium text-text-secondary mb-1.5">
            Name
          </label>
          <input id="uf-name" required value={name} onChange={(e) => setName(e.target.value)} className="input-field" />
        </div>
        <div>
          <label htmlFor="uf-email" className="block text-xs font-medium text-text-secondary mb-1.5">
            Email
          </label>
          <input
            id="uf-email"
            type="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            className="input-field"
          />
        </div>
        {!isEdit && (
          <div className="sm:col-span-2">
            <label htmlFor="uf-password" className="block text-xs font-medium text-text-secondary mb-1.5">
              Initial password
            </label>
            <input
              id="uf-password"
              type="password"
              required
              minLength={10}
              autoComplete="new-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="input-field"
            />
            <p className="text-xs text-text-muted mt-1">At least 10 characters, with letters and numbers.</p>
          </div>
        )}
      </div>

      {isSelf ? (
        <p className="text-xs text-text-secondary">
          You can&apos;t change your own roles, branches or status. Ask another administrator to do that.
        </p>
      ) : optionsError ? (
        <p role="alert" className="text-sm text-danger">
          Couldn&apos;t load the roles and branches you can assign.
        </p>
      ) : optionsLoading || !options ? (
        <div className="h-16 bg-surface-hover rounded animate-pulse" aria-busy="true" />
      ) : (
        <div className="grid sm:grid-cols-2 gap-4">
          <fieldset>
            <legend className="text-xs font-medium text-text-secondary mb-1.5">Roles</legend>
            <div className="space-y-1.5">
              {options.roles.map((r) => (
                <label key={r.id} className="flex items-center gap-2 text-sm text-strong">
                  <input
                    type="checkbox"
                    checked={roleIds.includes(r.id)}
                    onChange={() => setRoleIds((ids) => toggleInList(ids, r.id))}
                  />
                  {r.name}
                  <span className="text-xs text-text-muted">{r.permissions.length} permissions</span>
                </label>
              ))}
              {options.roles.length === 0 && <p className="text-xs text-text-muted">No roles available to you.</p>}
            </div>
          </fieldset>
          <fieldset>
            <legend className="text-xs font-medium text-text-secondary mb-1.5">Branches</legend>
            <div className="space-y-1.5">
              {options.branches.map((b) => (
                <label key={b.id} className="flex items-center gap-2 text-sm text-strong">
                  <input
                    type="checkbox"
                    checked={branchIds.includes(b.id)}
                    onChange={() => setBranchIds((ids) => toggleInList(ids, b.id))}
                  />
                  {b.name}
                  <span className="text-xs text-text-muted">{b.code}</span>
                </label>
              ))}
            </div>
            {activeBranchChoices.length > 1 && (
              <div className="mt-3">
                <label htmlFor="uf-active-branch" className="block text-xs font-medium text-text-secondary mb-1.5">
                  Signs in to
                </label>
                <select
                  id="uf-active-branch"
                  value={effectiveActiveBranch}
                  onChange={(e) => setActiveBranchId(e.target.value)}
                  className="input-field"
                >
                  {activeBranchChoices.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.name}
                    </option>
                  ))}
                </select>
              </div>
            )}
          </fieldset>
        </div>
      )}

      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving…" : isEdit ? "Save changes" : "Create user"}
        </button>
      </div>
    </form>
  );
}

function ResetPasswordForm({ user, onClose, onDone }: { user: AdminUser; onClose: () => void; onDone: () => void }) {
  const [password, setPassword] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await resetUserPassword(user.id, password);
      onDone();
    } catch (err) {
      setError(describe(err, "Couldn’t reset this password."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3" aria-label="Reset password">
      <h3 className="text-sm font-bold text-strong">Reset password for {user.name}</h3>
      <p className="text-xs text-text-secondary">
        This signs {user.isSelf ? "you" : "them"} out everywhere. Share the new password through a secure channel.
      </p>
      {error && (
        <div role="alert" className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">
          {error}
        </div>
      )}
      <div className="max-w-sm">
        <label htmlFor="rp-password" className="block text-xs font-medium text-text-secondary mb-1.5">
          New password
        </label>
        <input
          id="rp-password"
          type="password"
          required
          minLength={10}
          autoComplete="new-password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          className="input-field"
        />
        <p className="text-xs text-text-muted mt-1">At least 10 characters, with letters and numbers.</p>
      </div>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving…" : "Reset password"}
        </button>
      </div>
    </form>
  );
}
