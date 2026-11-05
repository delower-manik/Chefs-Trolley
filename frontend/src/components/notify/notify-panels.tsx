"use client";

import { useState } from "react";
import { Can } from "@/components/ui/can";
import { ApiError } from "@/lib/api/client";
import { formatDateTime } from "@/lib/format";
import { retryDelivery, sendAnnouncement, sendTestMessage, useDeliveryLog } from "@/hooks/use-notify";
import type { NotifyChannel } from "@/types/notify";

const CHANNEL_LABEL: Record<NotifyChannel, string> = { push: "App push", sms: "SMS", email: "Email" };

export function ChannelNotice() {
  const { channels } = useDeliveryLog();
  if (!channels) return null;
  const missing = (Object.keys(channels) as NotifyChannel[]).filter((c) => !channels[c].configured);
  if (missing.length === 0) return null;
  return (
    <div className="bg-warning/10 border border-warning/30 text-warning text-xs rounded-lg px-3 py-2">
      Not set up on the server yet: {missing.map((c) => CHANNEL_LABEL[c]).join(", ")}. Messages on those channels are logged as failed until their
      credentials are added to the server settings (.env).
    </div>
  );
}

export function SendDialog({ id, onClose, onDone }: { id: string; onClose: () => void; onDone: () => void }) {
  const { channels } = useDeliveryLog();
  const [chosen, setChosen] = useState<NotifyChannel[]>(["push"]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const toggle = (c: NotifyChannel) => setChosen((cur) => (cur.includes(c) ? cur.filter((x) => x !== c) : [...cur, c]));

  async function send() {
    setBusy(true);
    setError(null);
    try {
      const res = await sendAnnouncement(id, chosen);
      window.alert(`Queued: ${res.queued.push} push, ${res.queued.sms} SMS, ${res.queued.email} email. Results appear in the delivery log.`);
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't send this notification.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/60 grid place-items-center z-50 p-4" onClick={onClose}>
      <div className="card p-5 w-full max-w-md space-y-3" onClick={(e) => e.stopPropagation()}>
        <h3 className="font-bold text-strong">Send via</h3>
        {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
        {(["push", "sms", "email"] as NotifyChannel[]).map((c) => (
          <label key={c} className="flex items-center gap-2 text-sm text-text-secondary">
            <input type="checkbox" checked={chosen.includes(c)} onChange={() => toggle(c)} />
            {CHANNEL_LABEL[c]}
            {channels && !channels[c].configured && <span className="text-warning text-xs">(not set up)</span>}
          </label>
        ))}
        <p className="text-[11px] text-text-muted">
          SMS costs money per message and customers have no opt-out setting yet — choose it deliberately. There is no undo once queued.
        </p>
        <div className="flex gap-2 justify-end">
          <button className="btn-secondary" onClick={onClose}>
            Cancel
          </button>
          <button className="btn-primary" disabled={busy || chosen.length === 0} onClick={send}>
            {busy ? "Queuing..." : "Send"}
          </button>
        </div>
      </div>
    </div>
  );
}

export function DeliveryLog() {
  const [status, setStatus] = useState("");
  const { deliveries, channels, refresh } = useDeliveryLog(status || undefined);
  const [testChannel, setTestChannel] = useState<NotifyChannel>("sms");
  const [recipient, setRecipient] = useState("");
  const [note, setNote] = useState<string | null>(null);

  return (
    <div className="card overflow-hidden">
      <div className="p-4 border-b border-border flex flex-wrap items-center justify-between gap-3">
        <div>
          <h3 className="font-bold text-strong">Delivery log</h3>
          <p className="text-xs text-text-secondary">
            SMS {channels?.sms.configured ? `via ${channels.sms.driver}` : "not set up"} · Push {channels?.push.configured ? "on" : "not set up"} · Email{" "}
            {channels?.email.driver ?? "—"}
          </p>
        </div>
        <select value={status} onChange={(e) => setStatus(e.target.value)} className="input-field w-36 text-sm" aria-label="Filter by status">
          <option value="">All</option>
          <option value="queued">Queued</option>
          <option value="sent">Sent</option>
          <option value="failed">Failed</option>
        </select>
      </div>

      <Can permission="notifications.manage">
        <form
          className="p-4 border-b border-border flex flex-wrap gap-2 items-center"
          onSubmit={async (e) => {
            e.preventDefault();
            setNote(null);
            try {
              await sendTestMessage(testChannel, recipient);
              setNote("Test queued — it is sent by the next scheduled queue run (about a minute).");
              await refresh();
            } catch (err) {
              setNote(err instanceof ApiError ? err.message : "Couldn't queue the test.");
            }
          }}
        >
          <span className="text-xs text-text-secondary">Send a test:</span>
          <select aria-label="Test channel" value={testChannel} onChange={(e) => setTestChannel(e.target.value as NotifyChannel)} className="input-field w-28 text-sm">
            <option value="sms">SMS</option>
            <option value="email">Email</option>
            <option value="push">Push token</option>
          </select>
          <input
            required
            value={recipient}
            onChange={(e) => setRecipient(e.target.value)}
            className="input-field flex-1 min-w-[12rem] text-sm"
            placeholder={testChannel === "sms" ? "01XXXXXXXXX" : testChannel === "email" ? "you@example.com" : "device token"}
          />
          <button className="btn-secondary text-sm">Send test</button>
          {note && <span className="text-xs text-text-secondary w-full">{note}</span>}
        </form>
      </Can>

      {deliveries.length === 0 ? (
        <div className="p-8 text-center text-sm text-text-secondary">Nothing has been sent yet.</div>
      ) : (
        <table className="w-full text-sm">
          <thead className="bg-surface-hover text-text-secondary">
            <tr>
              <th className="text-left p-3 font-medium">When</th>
              <th className="text-left p-3 font-medium">Channel</th>
              <th className="text-left p-3 font-medium">To</th>
              <th className="text-left p-3 font-medium">Status</th>
              <th className="p-3" />
            </tr>
          </thead>
          <tbody>
            {deliveries.map((d) => (
              <tr key={d.id} className="border-t border-border">
                <td className="p-3 text-text-secondary whitespace-nowrap">{formatDateTime(d.createdAt)}</td>
                <td className="p-3 text-strong">
                  {CHANNEL_LABEL[d.channel]}
                  <div className="text-xs text-text-secondary">{d.purpose.replace("_", " ")}</div>
                </td>
                <td className="p-3 text-text-secondary font-mono text-xs">{d.recipient}</td>
                <td className="p-3">
                  <span
                    className={`badge ${
                      d.status === "sent" ? "bg-success/15 text-success" : d.status === "failed" ? "bg-danger/15 text-danger" : "bg-surface-hover text-text-secondary"
                    }`}
                  >
                    {d.status}
                  </span>
                  {d.error && <div className="text-xs text-danger mt-1 max-w-xs break-words">{d.error}</div>}
                </td>
                <td className="p-3 text-right">
                  {d.status === "failed" && (
                    <Can permission="notifications.manage">
                      <button
                        className="text-xs text-primary-fg"
                        onClick={async () => {
                          await retryDelivery(d.id);
                          await refresh();
                        }}
                      >
                        Retry
                      </button>
                    </Can>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}
