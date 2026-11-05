"use client";

import { useState } from "react";
import { useAttendanceRoster, markAttendance } from "@/hooks/use-hr";
import { ApiError } from "@/lib/api/client";
import { todayInDhaka } from "@/lib/format";
import { Can } from "@/components/ui/can";
import type { AttendanceStatus } from "@/types/hr";

const STATUS_OPTIONS: { value: AttendanceStatus; label: string; style: string }[] = [
  { value: "present", label: "Present", style: "bg-success/15 text-success" },
  { value: "half_day", label: "Half day", style: "bg-warning/15 text-warning" },
  { value: "on_leave", label: "On leave", style: "bg-primary/15 text-primary-fg" },
  { value: "absent", label: "Absent", style: "bg-danger/15 text-danger" },
];

export default function AttendancePage() {
  const [date, setDate] = useState(todayInDhaka());
  const { roster, isLoading, error, refresh } = useAttendanceRoster(date);
  const [busyId, setBusyId] = useState<string | null>(null);

  async function handleMark(employeeId: string, status: AttendanceStatus) {
    setBusyId(employeeId);
    try {
      await markAttendance({ employee_id: employeeId, date, status });
      refresh();
    } catch (err) {
      window.alert(err instanceof ApiError ? err.message : "Couldn't mark attendance.");
    } finally {
      setBusyId(null);
    }
  }

  return (
    <section className="max-w-4xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Attendance</h2>
          <p className="text-sm text-text-secondary">Today&apos;s roster — mark each employee once.</p>
        </div>
        <input aria-label="Attendance date" type="date" value={date} onChange={(e) => setDate(e.target.value)} className="input-field w-auto" />
      </div>

      <div className="card overflow-hidden">
        {error ? (
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load the roster.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 5 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : roster.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No active employees to mark yet.</div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">Employee</th>
                <th className="text-left p-3 font-medium">Status</th>
                <th className="text-right p-3 font-medium">Mark as</th>
              </tr>
            </thead>
            <tbody>
              {roster.map((r) => (
                <tr key={r.employeeId} className="border-t border-border">
                  <td className="p-3">
                    <div className="text-strong font-medium">{r.employeeName}</div>
                    <div className="text-xs text-text-secondary">{r.roleTitle}</div>
                  </td>
                  <td className="p-3">
                    {r.status ? (
                      <span className={`badge ${STATUS_OPTIONS.find((s) => s.value === r.status)?.style}`}>
                        {STATUS_OPTIONS.find((s) => s.value === r.status)?.label}
                      </span>
                    ) : (
                      <span className="badge bg-surface-hover text-text-muted">Not marked</span>
                    )}
                  </td>
                  <td className="p-3 text-right">
                    <Can permission="hr.manage">
                      <div className="flex gap-1 justify-end">
                        {STATUS_OPTIONS.map((opt) => (
                          <button
                            key={opt.value}
                            disabled={busyId === r.employeeId}
                            onClick={() => handleMark(r.employeeId, opt.value)}
                            className={`text-xs px-2 py-1 rounded ${
                              r.status === opt.value ? opt.style : "bg-surface-hover text-text-secondary hover:text-strong"
                            }`}
                          >
                            {opt.label}
                          </button>
                        ))}
                      </div>
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
