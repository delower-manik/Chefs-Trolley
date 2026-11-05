"use client";

import { useEffect, useState } from "react";
import { apiRequest } from "@/lib/api/client";
import { useEmployees, createEmployee, updateEmployee, deleteEmployee } from "@/hooks/use-hr";
import { formatBDT } from "@/lib/format";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";
import type { Employee, EmploymentType } from "@/types/hr";

export default function EmployeesPage() {
  const [search, setSearch] = useState("");
  const { employees, isLoading, error, refresh } = useEmployees(search || undefined);
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Employee | null>(null);

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Employees</h2>
          <p className="text-sm text-text-secondary">Your staff roster — not everyone here needs a login.</p>
        </div>
        <Can permission="hr.manage">
          <button
            className="btn-primary"
            onClick={() => {
              setEditing(null);
              setFormOpen(true);
            }}
          >
            + New employee
          </button>
        </Can>
      </div>

      <input
        className="input-field sm:max-w-xs"
        placeholder="Search by name or phone..."
        value={search}
        onChange={(e) => setSearch(e.target.value)}
      />

      {formOpen && (
        <EmployeeForm
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
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load employees.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 5 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : employees.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No employees added yet.</div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">Name</th>
                <th className="text-left p-3 font-medium">Role</th>
                <th className="text-left p-3 font-medium">Phone</th>
                <th className="text-right p-3 font-medium">Monthly salary</th>
                <th className="text-left p-3 font-medium">Status</th>
                <th className="text-right p-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {employees.map((emp) => (
                <tr key={emp.id} className="border-t border-border">
                  <td className="p-3 text-strong font-medium">{emp.name}</td>
                  <td className="p-3 text-text-secondary">{emp.roleTitle}</td>
                  <td className="p-3 text-text-secondary">{emp.phone}</td>
                  <td className="p-3 text-right text-text-secondary">{formatBDT(emp.monthlySalary)}</td>
                  <td className="p-3">
                    <span className={`badge ${emp.isActive ? "bg-success/15 text-success" : "bg-surface-hover text-text-secondary"}`}>
                      {emp.isActive ? "Active" : "Inactive"}
                    </span>
                  </td>
                  <td className="p-3 text-right">
                    <Can permission="hr.manage">
                      <button
                        className="text-primary-fg text-xs font-medium mr-3"
                        onClick={() => {
                          setEditing(emp);
                          setFormOpen(true);
                        }}
                      >
                        Edit
                      </button>
                      <button
                        className="text-danger text-xs font-medium"
                        onClick={async () => {
                          if (!window.confirm(`Delete "${emp.name}"?`)) return;
                          try {
                            await deleteEmployee(emp.id);
                            refresh();
                          } catch (err) {
                            window.alert(err instanceof ApiError ? err.message : "Couldn't delete this employee.");
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

function EmployeeForm({
  initial,
  onClose,
  onSaved,
}: {
  initial: Employee | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [name, setName] = useState(initial?.name ?? "");
  const [phone, setPhone] = useState(initial?.phone ?? "");
  const [roleTitle, setRoleTitle] = useState(initial?.roleTitle ?? "");
  const [employmentType, setEmploymentType] = useState<EmploymentType>(initial?.employmentType ?? "full_time");
  const [monthlySalary, setMonthlySalary] = useState(String(initial?.monthlySalary ?? ""));
  const [userId, setUserId] = useState(initial?.userId ?? "");
  const [logins, setLogins] = useState<{ id: string; name: string; email: string }[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Staff accounts that can be linked so this person can use the Staff app (needs users.manage; without it the list stays empty).
  useEffect(() => {
    apiRequest<{ data: { id: string; name: string; email: string }[] }>("/users").then((r) => setLogins(r.data)).catch(() => setLogins([]));
  }, []);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const payload = {
        name,
        phone,
        role_title: roleTitle,
        employment_type: employmentType,
        monthly_salary: Number(monthlySalary) || 0,
        user_id: userId || null,
      };
      if (initial) {
        await updateEmployee(initial.id, payload);
      } else {
        await createEmployee(payload);
      }
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't save this employee.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Name</label>
          <input aria-label="Name" required value={name} onChange={(e) => setName(e.target.value)} className="input-field" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Phone</label>
          <input aria-label="Phone" required value={phone} onChange={(e) => setPhone(e.target.value)} className="input-field" />
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Role / title</label>
          <input aria-label="Role / title"
            required
            value={roleTitle}
            onChange={(e) => setRoleTitle(e.target.value)}
            className="input-field"
            placeholder="e.g. Head Chef"
          />
        </div>
        {logins.length > 0 && (
          <div>
            <label className="block text-xs font-medium text-text-secondary mb-1.5">Staff app login</label>
            <select aria-label="Staff app login" value={userId} onChange={(e) => setUserId(e.target.value)} className="input-field">
              <option value="">None</option>
              {logins.map((u) => (
                <option key={u.id} value={u.id}>{u.name} ({u.email})</option>
              ))}
            </select>
          </div>
        )}
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Employment type</label>
          <select aria-label="Employment type" value={employmentType} onChange={(e) => setEmploymentType(e.target.value as EmploymentType)} className="input-field">
            <option value="full_time">Full time</option>
            <option value="part_time">Part time</option>
            <option value="contract">Contract</option>
          </select>
        </div>
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Monthly salary (BDT)</label>
          <input aria-label="Monthly salary (BDT)"
            type="number"
            min={0}
            value={monthlySalary}
            onChange={(e) => setMonthlySalary(e.target.value)}
            className="input-field"
          />
        </div>
      </div>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving..." : "Save employee"}
        </button>
      </div>
    </form>
  );
}
