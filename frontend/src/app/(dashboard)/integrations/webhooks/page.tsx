"use client";

import { useState } from "react";
import { useWebhooks, createWebhook, updateWebhook, deleteWebhook } from "@/hooks/use-integrations";
import { formatDateTime } from "@/lib/format";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";
import { redeliverWebhook, useWebhookDeliveries } from "@/hooks/use-notify";
import type { WebhookEvent } from "@/types/integrations";

const EVENTS: WebhookEvent[] = ["order.created", "order.cancelled", "order.delivered", "review.created", "customer.created"];

export default function WebhooksPage() {
  const { webhooks, isLoading, error, refresh } = useWebhooks();
  const [formOpen, setFormOpen] = useState(false);
  const [openId, setOpenId] = useState<string | null>(null);
  const [revealedSecret, setRevealedSecret] = useState<{ id: string; secret: string } | null>(null);

  return (
    <section className="max-w-4xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Webhooks</h2>
          <p className="text-sm text-text-secondary">Subscribe an external URL to Chefs Trolley events.</p>
        </div>
        <Can permission="integrations.manage">
          <button className="btn-primary" onClick={() => setFormOpen(true)}>
            + New webhook
          </button>
        </Can>
      </div>

      <div className="bg-surface-hover border border-border text-text-secondary text-xs rounded-lg px-3 py-2">
        Events are POSTed as JSON to your URL (https, public address only) by the scheduled queue, usually within a minute. Each request carries{" "}
        <code className="text-primary-fg">X-ChefsTrolley-Signature: t=&lt;unix&gt;,v1=&lt;hmac&gt;</code> — the HMAC-SHA256 of{" "}
        <code className="text-primary-fg">&quot;&lt;t&gt;.&lt;raw body&gt;&quot;</code> with your signing secret. Reject old timestamps and de-duplicate on{" "}
        <code className="text-primary-fg">X-ChefsTrolley-Delivery</code>: failed deliveries are retried with backoff.
      </div>

      {revealedSecret && (
        <div className="bg-primary/10 border border-primary/30 text-sm rounded-lg px-3 py-2">
          <p className="text-strong font-medium mb-1">Signing secret (shown once — copy it now)</p>
          <code className="text-xs text-primary-fg break-all">{revealedSecret.secret}</code>
        </div>
      )}

      {formOpen && (
        <NewWebhookForm
          onClose={() => setFormOpen(false)}
          onSaved={(id, secret) => {
            setFormOpen(false);
            setRevealedSecret({ id, secret });
            refresh();
          }}
        />
      )}

      <div className="card overflow-hidden">
        {error ? (
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load webhooks.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : webhooks.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No webhooks configured yet.</div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">URL</th>
                <th className="text-left p-3 font-medium">Event</th>
                <th className="text-left p-3 font-medium">Status</th>
                <th className="text-left p-3 font-medium">Created</th>
                <th className="text-right p-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {webhooks.map((wh) => (
                <tr key={wh.id} className="border-t border-border">
                  <td className="p-3 text-strong font-mono text-xs break-all">{wh.url}</td>
                  <td className="p-3 text-text-secondary">{wh.event}</td>
                  <td className="p-3">
                    <Can permission="integrations.manage">
                      <button
                        className={`badge ${wh.isActive ? "bg-success/15 text-success" : "bg-surface-hover text-text-secondary"}`}
                        onClick={async () => {
                          await updateWebhook(wh.id, { is_active: !wh.isActive });
                          refresh();
                        }}
                      >
                        {wh.isActive ? "Active" : "Paused"}
                      </button>
                    </Can>
                  </td>
                  <td className="p-3 text-text-secondary">{formatDateTime(wh.createdAt)}</td>
                  <td className="p-3 text-right">
                    <button className="text-primary-fg text-xs font-medium mr-3" onClick={() => setOpenId(openId === wh.id ? null : wh.id)}>
                      {openId === wh.id ? "Hide log" : "Deliveries"}
                    </button>
                    <Can permission="integrations.manage">
                      <button
                        className="text-danger text-xs font-medium"
                        onClick={async () => {
                          if (!window.confirm("Delete this webhook?")) return;
                          try {
                            await deleteWebhook(wh.id);
                            refresh();
                          } catch (err) {
                            window.alert(err instanceof ApiError ? err.message : "Couldn't delete this webhook.");
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

      {openId && <DeliveriesPanel webhookId={openId} />}
    </section>
  );
}

function DeliveriesPanel({ webhookId }: { webhookId: string }) {
  const { deliveries, isLoading, refresh } = useWebhookDeliveries(webhookId);
  return (
    <div className="card overflow-hidden">
      <div className="p-4 border-b border-border font-bold text-strong">Recent deliveries</div>
      {isLoading ? (
        <div className="h-16 bg-surface-hover animate-pulse" />
      ) : deliveries.length === 0 ? (
        <div className="p-6 text-center text-sm text-text-secondary">Nothing delivered to this URL yet.</div>
      ) : (
        <table className="w-full text-sm">
          <tbody>
            {deliveries.map((d) => (
              <tr key={d.id} className="border-t border-border first:border-0">
                <td className="p-3 text-text-secondary whitespace-nowrap">{formatDateTime(d.createdAt)}</td>
                <td className="p-3 text-strong">{d.event}</td>
                <td className="p-3">
                  <span
                    className={`badge ${
                      d.status === "sent" ? "bg-success/15 text-success" : d.status === "failed" ? "bg-danger/15 text-danger" : "bg-surface-hover text-text-secondary"
                    }`}
                  >
                    {d.status}
                  </span>
                  <span className="text-xs text-text-secondary ml-2">
                    {d.attempts} attempt(s){d.responseStatus ? ` · HTTP ${d.responseStatus}` : ""}
                  </span>
                  {d.error && <div className="text-xs text-danger mt-1 break-words">{d.error}</div>}
                  {d.status === "queued" && d.nextAttemptAt && <div className="text-xs text-text-secondary mt-1">Retry after {formatDateTime(d.nextAttemptAt)}</div>}
                </td>
                <td className="p-3 text-right">
                  {d.status !== "sent" && (
                    <Can permission="integrations.manage">
                      <button
                        className="text-xs text-primary-fg"
                        onClick={async () => {
                          try {
                            await redeliverWebhook(d.id);
                            await refresh();
                          } catch (err) {
                            window.alert(err instanceof ApiError ? err.message : "Couldn't redeliver.");
                          }
                        }}
                      >
                        Redeliver
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

function NewWebhookForm({ onClose, onSaved }: { onClose: () => void; onSaved: (id: string, secret: string) => void }) {
  const [url, setUrl] = useState("");
  const [event, setEvent] = useState<WebhookEvent>("order.created");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const result = await createWebhook({ url, event });
      onSaved(result.data.id, result.data.secret ?? "");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't create this webhook.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div>
        <label className="block text-xs font-medium text-text-secondary mb-1.5">Target URL</label>
        <input aria-label="Target URL"
          required
          type="url"
          value={url}
          onChange={(e) => setUrl(e.target.value)}
          className="input-field"
          placeholder="https://example.com/webhooks/chefs-trolley"
        />
      </div>
      <div>
        <label className="block text-xs font-medium text-text-secondary mb-1.5">Event</label>
        <select aria-label="Event" value={event} onChange={(e) => setEvent(e.target.value as WebhookEvent)} className="input-field">
          {EVENTS.map((ev) => (
            <option key={ev} value={ev}>
              {ev}
            </option>
          ))}
        </select>
      </div>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving..." : "Save webhook"}
        </button>
      </div>
    </form>
  );
}
