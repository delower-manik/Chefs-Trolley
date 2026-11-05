"use client";

import { useState } from "react";
import { ApiError } from "@/lib/api/client";
import { runBackupNow, useBackups, verifyBackup } from "@/hooks/use-account";
import { formatDateTime } from "@/lib/format";
import type { BackupRun } from "@/types/account";

const size = (b: number | null) => (b == null ? "—" : b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);

export default function BackupsPage() {
  const { runs, meta, refresh } = useBackups();
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

  async function act(id: string, fn: () => Promise<string>) {
    setBusy(id);
    setMsg(null);
    try {
      setMsg({ ok: true, text: await fn() });
    } catch (e) {
      setMsg({ ok: false, text: e instanceof ApiError ? e.message : "That didn't work." });
    } finally {
      setBusy(null);
      void refresh();
    }
  }

  const lastOk = meta?.lastOkAt ? new Date(meta.lastOkAt) : null;
  const stale = !lastOk || Date.now() - lastOk.getTime() > 36 * 3600 * 1000;

  return (
    <section className="max-w-4xl mx-auto space-y-5">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 className="text-2xl font-bold text-strong">Backups</h2>
          <p className="text-sm text-text-secondary">Database and uploaded images, encrypted, every night. Last good backup: {lastOk ? formatDateTime(meta!.lastOkAt!) : "never"}.</p>
        </div>
        <button className="btn-primary" disabled={busy !== null} onClick={() => act("new", async () => { const r = await runBackupNow(); return r.status === "ok" ? "Backup finished." : `Backup failed: ${r.error}`; })}>
          {busy === "new" ? "Backing up…" : "Back up now"}
        </button>
      </div>

      {stale && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">No good backup in the last 36 hours. Check that the scheduler cron is running and the encryption key is set.</div>}
      {meta && !meta.keyConfigured && (
        <div className="bg-warning/10 border border-warning/30 text-warning text-xs rounded-lg px-3 py-2">
          BACKUP_ENCRYPTION_KEY is not set, so backups use APP_KEY. Set a dedicated key and keep a copy somewhere other than this server — without it a backup cannot be opened.
        </div>
      )}
      {meta && !meta.offsiteDisk && (
        <div className="bg-warning/10 border border-warning/30 text-warning text-xs rounded-lg px-3 py-2">
          No off-site copy is configured: if this server is lost, these backups are lost with it. Set BACKUP_OFFSITE_DISK, or download backups regularly.
        </div>
      )}
      {msg && <div role="alert" className={`text-sm rounded-lg px-3 py-2 border ${msg.ok ? "bg-success/10 border-success/30 text-success" : "bg-danger/10 border-danger/30 text-danger"}`}>{msg.text}</div>}

      <div className="card overflow-hidden">
        {runs.length === 0 ? (
          <div className="p-8 text-center text-sm text-text-secondary">No backups yet.</div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">When</th>
                <th className="text-left p-3 font-medium">Result</th>
                <th className="text-left p-3 font-medium">Size</th>
                <th className="text-left p-3 font-medium">Off-site</th>
                <th className="p-3" />
              </tr>
            </thead>
            <tbody>
              {runs.map((r) => (
                <Row key={r.id} r={r} busy={busy === r.id} onVerify={() => act(r.id, async () => { const v = await verifyBackup(r.id); return v.message; })} />
              ))}
            </tbody>
          </table>
        )}
      </div>
      <p className="text-xs text-text-muted">
        Kept: every backup for {meta?.retention.daily ?? 7} days, then one a week for {meta?.retention.weekly ?? 4} weeks, then one a month for {meta?.retention.monthly ?? 6} months.
        &ldquo;Verified&rdquo; means the file was decrypted and every checksum matched — it does not mean the database was restored. Restore steps are in the README.
      </p>
    </section>
  );
}

function Row({ r, busy, onVerify }: { r: BackupRun; busy: boolean; onVerify: () => void }) {
  const tone = r.status === "ok" ? "bg-success/15 text-success" : r.status === "failed" ? "bg-danger/15 text-danger" : "bg-surface-hover text-text-secondary";
  return (
    <tr className="border-t border-border">
      <td className="p-3 text-text-secondary whitespace-nowrap">{r.startedAt ? formatDateTime(r.startedAt) : "—"}<div className="text-[11px]">{r.trigger}</div></td>
      <td className="p-3">
        <span className={`badge ${tone}`}>{r.status}</span>
        {r.verifiedAt && <span className="badge bg-primary/15 text-primary-fg ml-1">verified</span>}
        {r.error && <div className="text-xs text-danger mt-1 max-w-xs break-words">{r.error}</div>}
      </td>
      <td className="p-3 text-text-secondary">{size(r.sizeBytes)}{r.includesMedia ? " + images" : ""}</td>
      <td className="p-3 text-text-secondary">{r.offsiteDisk ? (r.offsiteOk ? `✓ ${r.offsiteDisk}` : "failed") : "—"}</td>
      <td className="p-3 text-right whitespace-nowrap space-x-3">
        {r.status === "ok" && (
          <>
            <button className="text-xs text-primary-fg" disabled={busy} onClick={onVerify}>{busy ? "Checking…" : "Verify"}</button>
            <DownloadLink id={r.id} name={r.filename ?? "backup.zip.enc"} />
          </>
        )}
      </td>
    </tr>
  );
}

function DownloadLink({ id, name }: { id: string; name: string }) {
  // The endpoint needs the bearer token, so fetch it as a blob rather than a plain link.
  return (
    <button
      className="text-xs text-primary-fg"
      onClick={async () => {
        const { apiRequest } = await import("@/lib/api/client");
        const blob = await apiRequest<Blob>(`/backups/${id}/download`, { parseAs: "blob" });
        const url = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = name;
        a.click();
        URL.revokeObjectURL(url);
      }}
    >
      Download
    </button>
  );
}
