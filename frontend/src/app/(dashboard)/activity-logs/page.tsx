"use client";

import { useMemo, useState } from "react";
import { useActivityActions, useActivityLogs } from "@/hooks/use-admin";
import { useDebounced } from "@/hooks/use-debounced";
import { useAuth } from "@/lib/auth/auth-context";
import { ApiError } from "@/lib/api/client";
import { actionCategory, categoriesOf } from "@/lib/admin-utils";
import { formatDateTime } from "@/lib/format";
import { Pagination } from "@/components/ui/pagination";
import type { AuditLogEntry } from "@/types/admin";

const CATEGORY_STYLE: Record<string, string> = {
  order: "bg-primary/15 text-primary-fg",
  user: "bg-warning/15 text-warning",
  role: "bg-warning/15 text-warning",
  branch: "bg-warning/15 text-warning",
  auth: "bg-surface-hover text-text-secondary",
};

export default function ActivityLogsPage() {
  const { user } = useAuth();
  const isSuperAdmin = user?.roles.some((r) => r.name === "Super Admin") ?? false;

  const [search, setSearch] = useState("");
  const [category, setCategory] = useState("");
  const [action, setAction] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);
  const [openId, setOpenId] = useState<string | null>(null);

  const debouncedSearch = useDebounced(search);
  const { actions } = useActivityActions();
  const categories = useMemo(() => categoriesOf(actions), [actions]);
  const actionChoices = useMemo(
    () => actions.filter((a) => category === "" || actionCategory(a) === category),
    [actions, category]
  );

  const rangeInvalid = from !== "" && to !== "" && to < from;
  const { entries, meta, isLoading, error } = useActivityLogs({
    q: debouncedSearch,
    category,
    action,
    // An inverted range would be rejected by the API; don't send it.
    from: rangeInvalid ? "" : from,
    to: rangeInvalid ? "" : to,
    page,
  });

  function reset<T>(setter: (v: T) => void) {
    return (value: T) => {
      setter(value);
      setPage(1);
    };
  }

  const hasFilters = debouncedSearch !== "" || category !== "" || action !== "" || from !== "" || to !== "";

  return (
    <section className="max-w-6xl mx-auto space-y-5">
      <div>
        <h2 className="text-2xl font-bold text-strong">Activity Logs</h2>
        <p className="text-sm text-text-secondary">
          A permanent, read-only record of important actions. Entries can&apos;t be edited or deleted.{" "}
          {isSuperAdmin
            ? "You can see every branch."
            : "You see entries for your active branch; account and role changes are visible to Super Admins only."}
        </p>
      </div>

      <div className="card p-4 flex flex-wrap items-end gap-3">
        <div className="grow max-w-xs">
          <label htmlFor="log-search" className="block text-xs font-medium text-text-secondary mb-1.5">
            Search
          </label>
          <input
            id="log-search"
            type="search"
            placeholder="Person or action"
            value={search}
            onChange={(e) => reset(setSearch)(e.target.value)}
            className="input-field"
          />
        </div>
        <div>
          <label htmlFor="log-category" className="block text-xs font-medium text-text-secondary mb-1.5">
            Category
          </label>
          <select
            id="log-category"
            value={category}
            onChange={(e) => {
              setCategory(e.target.value);
              setAction("");
              setPage(1);
            }}
            className="input-field"
          >
            <option value="">All</option>
            {categories.map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="log-action" className="block text-xs font-medium text-text-secondary mb-1.5">
            Action
          </label>
          <select id="log-action" value={action} onChange={(e) => reset(setAction)(e.target.value)} className="input-field">
            <option value="">All</option>
            {actionChoices.map((a) => (
              <option key={a} value={a}>
                {a}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="log-from" className="block text-xs font-medium text-text-secondary mb-1.5">
            From
          </label>
          <input id="log-from" type="date" value={from} onChange={(e) => reset(setFrom)(e.target.value)} className="input-field" />
        </div>
        <div>
          <label htmlFor="log-to" className="block text-xs font-medium text-text-secondary mb-1.5">
            To
          </label>
          <input id="log-to" type="date" value={to} onChange={(e) => reset(setTo)(e.target.value)} className="input-field" />
        </div>
      </div>

      {rangeInvalid && (
        <div role="alert" className="bg-warning/10 border border-warning/30 text-warning text-sm rounded-lg px-3 py-2">
          The “To” date is before the “From” date, so the date range is being ignored.
        </div>
      )}

      <div className="card overflow-hidden">
        {error ? (
          <div role="alert" className="p-10 text-center text-sm text-text-secondary">
            {error instanceof ApiError ? error.message : "Couldn’t load the activity log."}
          </div>
        ) : isLoading ? (
          <div className="p-4 space-y-3" aria-busy="true">
            {Array.from({ length: 6 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : entries.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">
            {hasFilters ? "No activity matches these filters." : "No activity recorded yet."}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-surface-hover text-text-secondary">
                <tr>
                  <th className="text-left p-3 font-medium">When</th>
                  <th className="text-left p-3 font-medium">Who</th>
                  <th className="text-left p-3 font-medium">Action</th>
                  <th className="text-left p-3 font-medium">Subject</th>
                  <th className="text-right p-3 font-medium">Details</th>
                </tr>
              </thead>
              <tbody>
                {entries.map((entry) => (
                  <LogRow
                    key={entry.id}
                    entry={entry}
                    open={openId === entry.id}
                    onToggle={() => setOpenId(openId === entry.id ? null : entry.id)}
                  />
                ))}
              </tbody>
            </table>
          </div>
        )}
        {meta && <Pagination page={meta.currentPage} lastPage={meta.lastPage} total={meta.total} onChange={setPage} />}
      </div>
    </section>
  );
}

function LogRow({ entry, open, onToggle }: { entry: AuditLogEntry; open: boolean; onToggle: () => void }) {
  const hasChanges = entry.changes !== null && Object.keys(entry.changes).length > 0;
  const style = CATEGORY_STYLE[actionCategory(entry.action)] ?? "bg-surface-hover text-text-secondary";

  return (
    <>
      <tr className="border-t border-border align-top">
        <td className="p-3 text-text-secondary whitespace-nowrap">{formatDateTime(entry.createdAt)}</td>
        <td className="p-3 text-strong">
          {entry.actorName ?? <span className="text-text-muted">System</span>}
          {entry.ipAddress && <div className="text-xs text-text-muted">{entry.ipAddress}</div>}
        </td>
        <td className="p-3">
          <span className={`badge ${style}`}>{entry.action}</span>
        </td>
        <td className="p-3 text-text-secondary">
          {entry.subjectType}
          <div className="text-xs text-text-muted break-all">{entry.subjectId}</div>
        </td>
        <td className="p-3 text-right">
          {hasChanges ? (
            <button className="text-primary-fg text-xs font-medium" aria-expanded={open} onClick={onToggle}>
              {open ? "Hide" : "Show"}
            </button>
          ) : (
            <span className="text-text-muted text-xs">—</span>
          )}
        </td>
      </tr>
      {open && hasChanges && (
        <tr className="bg-surface-raised">
          <td colSpan={5} className="p-3">
            {/* Rendered as text inside <pre>: log content is data, never markup. */}
            <pre className="text-xs text-text-secondary whitespace-pre-wrap break-words">
              {JSON.stringify(entry.changes, null, 2)}
            </pre>
          </td>
        </tr>
      )}
    </>
  );
}
