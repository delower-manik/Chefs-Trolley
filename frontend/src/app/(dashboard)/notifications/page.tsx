"use client";

import { useState } from "react";
import { useNotifications, createNotification, deleteNotification } from "@/hooks/use-growth";
import { ChannelNotice, DeliveryLog, SendDialog } from "@/components/notify/notify-panels";
import { useCustomers } from "@/hooks/use-customers";
import { formatDateTime } from "@/lib/format";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";
import type { NotificationAudience } from "@/types/growth";

export default function NotificationsPage() {
  const { notifications, isLoading, error, refresh } = useNotifications();
  const { customers } = useCustomers();
  const [formOpen, setFormOpen] = useState(false);
  const [sending, setSending] = useState<string | null>(null);

  return (
    <section className="max-w-4xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Notifications</h2>
          <p className="text-sm text-text-secondary">Announcements to customers by app push, SMS or email.</p>
        </div>
        <Can permission="notifications.manage">
          <button className="btn-primary" onClick={() => setFormOpen(true)}>
            + New notification
          </button>
        </Can>
      </div>

      <ChannelNotice />

      {formOpen && (
        <NewNotificationForm
          customers={customers}
          onClose={() => setFormOpen(false)}
          onSaved={() => {
            setFormOpen(false);
            refresh();
          }}
        />
      )}

      {sending && (
        <SendDialog
          id={sending}
          onClose={() => setSending(null)}
          onDone={() => {
            setSending(null);
            refresh();
          }}
        />
      )}

      <div className="card overflow-hidden">
        {error ? (
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load notifications.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <div key={i} className="h-10 bg-surface-hover rounded animate-pulse" />
            ))}
          </div>
        ) : notifications.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No notifications recorded yet.</div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-surface-hover text-text-secondary">
              <tr>
                <th className="text-left p-3 font-medium">Title</th>
                <th className="text-left p-3 font-medium">Audience</th>
                <th className="text-left p-3 font-medium">Status</th>
                <th className="text-right p-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {notifications.map((n) => (
                <tr key={n.id} className="border-t border-border">
                  <td className="p-3">
                    <div className="text-strong font-medium">{n.title}</div>
                    <div className="text-xs text-text-secondary">{n.body}</div>
                  </td>
                  <td className="p-3 text-text-secondary">
                    {n.audience === "all_customers" ? "All customers" : n.customerName ?? "One customer"}
                  </td>
                  <td className="p-3">
                    <span className={`badge ${n.status === "sent" ? "bg-success/15 text-success" : "bg-surface-hover text-text-secondary"}`}>
                      {n.status}
                    </span>
                    {n.sentAt && <div className="text-xs text-text-secondary mt-1">{formatDateTime(n.sentAt)}</div>}
                  </td>
                  <td className="p-3 text-right">
                    <Can permission="notifications.manage">
                      {n.status === "draft" && (
                        <button className="text-primary-fg text-xs font-medium mr-3" onClick={() => setSending(n.id)}>
                          Send…
                        </button>
                      )}
                      <button
                        className="text-danger text-xs font-medium"
                        onClick={async () => {
                          if (!window.confirm("Delete this notification?")) return;
                          try {
                            await deleteNotification(n.id);
                            refresh();
                          } catch (err) {
                            window.alert(err instanceof ApiError ? err.message : "Couldn't delete this notification.");
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

      <DeliveryLog />
    </section>
  );
}

function NewNotificationForm({
  customers,
  onClose,
  onSaved,
}: {
  customers: { id: string; name: string }[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");
  const [audience, setAudience] = useState<NotificationAudience>("all_customers");
  const [customerId, setCustomerId] = useState(customers[0]?.id ?? "");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await createNotification({
        title,
        body,
        audience,
        customer_id: audience === "specific_customer" ? customerId : undefined,
      });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't save this notification.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="card p-5 space-y-3">
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{error}</div>}
      <div>
        <label className="block text-xs font-medium text-text-secondary mb-1.5">Title</label>
        <input aria-label="Title" required value={title} onChange={(e) => setTitle(e.target.value)} className="input-field" />
      </div>
      <div>
        <label className="block text-xs font-medium text-text-secondary mb-1.5">Message</label>
        <textarea aria-label="Message" required value={body} onChange={(e) => setBody(e.target.value)} className="input-field" rows={3} />
      </div>
      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-medium text-text-secondary mb-1.5">Audience</label>
          <select aria-label="Audience" value={audience} onChange={(e) => setAudience(e.target.value as NotificationAudience)} className="input-field">
            <option value="all_customers">All customers</option>
            <option value="specific_customer">One customer</option>
          </select>
        </div>
        {audience === "specific_customer" && (
          <div>
            <label className="block text-xs font-medium text-text-secondary mb-1.5">Customer</label>
            <select aria-label="Customer" value={customerId} onChange={(e) => setCustomerId(e.target.value)} className="input-field">
              {customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
        )}
      </div>
      <div className="flex gap-2 justify-end">
        <button type="button" onClick={onClose} className="btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="btn-primary">
          {saving ? "Saving..." : "Save draft"}
        </button>
      </div>
    </form>
  );
}
