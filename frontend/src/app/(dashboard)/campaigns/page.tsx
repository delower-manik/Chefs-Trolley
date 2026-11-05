"use client";

import { useEffect, useState } from "react";
import { Can } from "@/components/ui/can";
import { ApiError } from "@/lib/api/client";
import { formatDateTime } from "@/lib/format";
import { campaignAudience, cancelCampaign, deleteCampaign, saveCampaign, sendCampaign, useCampaign, useCampaigns, useSegments } from "@/hooks/use-marketing";
import type { Campaign, CampaignAudience, CampaignStatus, Channel } from "@/types/marketing";

const STATUS_STYLE: Record<CampaignStatus, string> = {
  draft: "bg-surface-hover text-text-secondary",
  scheduled: "bg-primary/15 text-primary-fg",
  sending: "bg-warning/15 text-warning",
  sent: "bg-success/15 text-success",
  cancelled: "bg-danger/15 text-danger",
};
const CHANNEL_LABEL: Record<Channel, string> = { push: "App push", sms: "SMS", email: "Email" };

export default function CampaignsPage() {
  const { campaigns, isLoading, error, refresh } = useCampaigns();
  const [editing, setEditing] = useState<Campaign | "new" | null>(null);
  const [sending, setSending] = useState<Campaign | null>(null);
  const [openId, setOpenId] = useState<string | null>(null);

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div className="flex items-center justify-between gap-3">
        <div>
          <h2 className="text-2xl font-bold text-strong">Campaigns</h2>
          <p className="text-sm text-text-secondary">
            Send an offer to a segment by app push, SMS or email — now or at a set time. Customers who opted out are never messaged. Sending needs the cron
            job from Notifications set up.
          </p>
        </div>
        <Can permission="campaigns.manage">
          <button className="btn-primary" onClick={() => setEditing("new")}>+ New campaign</button>
        </Can>
      </div>

      {editing && (
        <CampaignForm
          campaign={editing === "new" ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            void refresh();
          }}
        />
      )}
      {sending && (
        <SendDialog
          campaign={sending}
          onClose={() => setSending(null)}
          onDone={() => {
            setSending(null);
            void refresh();
          }}
        />
      )}

      <div className="card overflow-hidden">
        {error ? (
          <div className="p-10 text-center text-sm text-text-secondary">Couldn&apos;t load campaigns.</div>
        ) : isLoading ? (
          <div className="p-4 space-y-3">{Array.from({ length: 3 }).map((_, i) => <div key={i} className="h-12 bg-surface-hover rounded animate-pulse" />)}</div>
        ) : campaigns.length === 0 ? (
          <div className="p-10 text-center text-sm text-text-secondary">No campaigns yet.</div>
        ) : (
          <ul className="divide-y divide-border">
            {campaigns.map((c) => (
              <li key={c.id} className="p-4 space-y-2">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <div className="text-strong font-semibold">
                      {c.name} <span className={`badge ml-2 ${STATUS_STYLE[c.status]}`}>{c.status}</span>
                    </div>
                    <div className="text-xs text-text-secondary">
                      {c.segmentName ?? "All customers"} · {c.channels.map((ch) => CHANNEL_LABEL[ch]).join(", ")}
                      {c.status === "scheduled" && c.scheduledAt && ` · goes out ${formatDateTime(c.scheduledAt)}`}
                      {c.status === "sent" && c.sentAt && ` · sent ${formatDateTime(c.sentAt)} to ${c.audienceSize} people`}
                    </div>
                  </div>
                  <div className="flex gap-3 text-sm">
                    {(c.status === "sent" || c.status === "sending") && <button className="text-primary-fg" onClick={() => setOpenId(openId === c.id ? null : c.id)}>Results</button>}
                    <Can permission="campaigns.manage">
                      {(c.status === "draft" || c.status === "scheduled") && (
                        <>
                          <button className="text-primary-fg" onClick={() => setSending(c)}>Send now</button>
                          <button className="text-text-secondary" onClick={() => setEditing(c)}>Edit</button>
                          <button
                            className="text-text-secondary"
                            onClick={async () => {
                              if (!window.confirm("Cancel this campaign? It will not be sent.")) return;
                              await cancelCampaign(c.id);
                              void refresh();
                            }}
                          >
                            Cancel
                          </button>
                        </>
                      )}
                      {(c.status === "draft" || c.status === "cancelled") && (
                        <button
                          className="text-danger"
                          onClick={async () => {
                            if (!window.confirm("Delete this campaign?")) return;
                            await deleteCampaign(c.id);
                            void refresh();
                          }}
                        >
                          Delete
                        </button>
                      )}
                    </Can>
                  </div>
                </div>
                <div className="text-sm text-text-secondary"><strong className="text-strong">{c.title}</strong> — {c.body}</div>
                {openId === c.id && <Results id={c.id} />}
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  );
}

function Results({ id }: { id: string }) {
  const { campaign } = useCampaign(id);
  if (!campaign) return <div className="h-12 bg-surface-hover rounded animate-pulse" />;
  const rows = Object.entries(campaign.deliveries ?? {});
  return (
    <div className="bg-surface-hover rounded-lg p-3 text-sm space-y-1">
      <div className="text-text-secondary">Queued: {campaign.queued ? `${campaign.queued.push} push, ${campaign.queued.sms} SMS, ${campaign.queued.email} email` : "—"}</div>
      {rows.length === 0 && <div className="text-text-secondary">No delivery results yet — they appear as the queue runs.</div>}
      {rows.map(([channel, statuses]) => (
        <div key={channel} className="text-strong">
          {CHANNEL_LABEL[channel as Channel] ?? channel}:{" "}
          {Object.entries(statuses).map(([status, n]) => `${n} ${status}`).join(", ")}
        </div>
      ))}
    </div>
  );
}

function CampaignForm({ campaign, onClose, onSaved }: { campaign: Campaign | null; onClose: () => void; onSaved: () => void }) {
  const { segments } = useSegments();
  const [name, setName] = useState(campaign?.name ?? "");
  const [segmentId, setSegmentId] = useState(campaign?.segmentId ?? "");
  const [title, setTitle] = useState(campaign?.title ?? "");
  const [body, setBody] = useState(campaign?.body ?? "");
  const [channels, setChannels] = useState<Channel[]>(campaign?.channels ?? ["push"]);
  const [when, setWhen] = useState(campaign?.scheduledAt ? toLocalInput(campaign.scheduledAt) : "");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const toggle = (c: Channel) => setChannels((cur) => (cur.includes(c) ? cur.filter((x) => x !== c) : [...cur, c]));

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await saveCampaign(campaign?.id ?? null, {
        name, segment_id: segmentId || null, title, body, channels,
        scheduled_at: when ? new Date(when).toISOString() : null,
      });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't save the campaign.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={submit} className="card p-5 space-y-3">
      <h3 className="font-bold text-strong">{campaign ? "Edit campaign" : "New campaign"}</h3>
      {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2" role="alert">{error}</div>}
      <div className="grid sm:grid-cols-2 gap-3">
        <label className="text-xs text-text-secondary">Internal name
          <input required value={name} onChange={(e) => setName(e.target.value)} maxLength={120} className="input-field mt-1" />
        </label>
        <label className="text-xs text-text-secondary">Audience
          <select value={segmentId} onChange={(e) => setSegmentId(e.target.value)} className="input-field mt-1">
            <option value="">All customers (not opted out)</option>
            {segments.map((s) => <option key={s.id} value={s.id}>{s.name} ({s.size})</option>)}
          </select>
        </label>
      </div>
      <label className="text-xs text-text-secondary block">Title / email subject
        <input required value={title} onChange={(e) => setTitle(e.target.value)} maxLength={120} className="input-field mt-1" />
      </label>
      <label className="text-xs text-text-secondary block">Message
        <textarea required value={body} onChange={(e) => setBody(e.target.value)} maxLength={600} rows={4} className="input-field mt-1" />
        <span className="text-[11px]">{body.length}/600 · SMS is title + message together; long messages cost more than one SMS.</span>
      </label>
      <div className="flex flex-wrap gap-4 text-sm text-text-secondary">
        {(["push", "sms", "email"] as Channel[]).map((c) => (
          <label key={c} className="flex items-center gap-2">
            <input type="checkbox" checked={channels.includes(c)} onChange={() => toggle(c)} /> {CHANNEL_LABEL[c]}
          </label>
        ))}
      </div>
      <label className="text-xs text-text-secondary block">Send later (optional — leave empty to keep as a draft)
        <input type="datetime-local" value={when} onChange={(e) => setWhen(e.target.value)} className="input-field mt-1 w-64" />
      </label>
      <div className="flex gap-2 justify-end">
        <button type="button" className="btn-secondary" onClick={onClose}>Cancel</button>
        <button className="btn-primary" disabled={busy || channels.length === 0}>{busy ? "Saving..." : when ? "Schedule" : "Save draft"}</button>
      </div>
    </form>
  );
}

function toLocalInput(iso: string): string {
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function SendDialog({ campaign, onClose, onDone }: { campaign: Campaign; onClose: () => void; onDone: () => void }) {
  const [audience, setAudience] = useState<CampaignAudience | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Load the real audience once so the person confirms the number they actually saw.
  useEffect(() => {
    campaignAudience(campaign.id).then(setAudience).catch(() => setError("Couldn't work out who would receive this."));
  }, [campaign.id]);

  async function send() {
    if (!audience) return;
    setBusy(true);
    setError(null);
    try {
      await sendCampaign(campaign.id, audience.total);
      onDone();
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) setAudience(await campaignAudience(campaign.id));
      setError(err instanceof ApiError ? err.message : "Couldn't send.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/60 grid place-items-center z-50 p-4" onClick={onClose}>
      <div className="card p-5 w-full max-w-md space-y-3" onClick={(e) => e.stopPropagation()}>
        <h3 className="font-bold text-strong">Send &ldquo;{campaign.name}&rdquo; now?</h3>
        {error && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2" role="alert">{error}</div>}
        {audience ? (
          <div className="text-sm text-text-secondary space-y-1">
            <div className="text-strong"><strong>{audience.total}</strong> people will receive this.</div>
            {campaign.channels.includes("sms") && <div>{audience.sms} SMS — each one is charged by your SMS provider.</div>}
            {campaign.channels.includes("email") && <div>{audience.email} emails, each with an unsubscribe link.</div>}
            {campaign.channels.includes("push") && <div>{audience.push} app devices (customers who have the app).</div>}
            <div className="text-xs">There is no undo once queued.</div>
          </div>
        ) : (
          <div className="h-12 bg-surface-hover rounded animate-pulse" />
        )}
        <div className="flex gap-2 justify-end">
          <button className="btn-secondary" onClick={onClose}>Cancel</button>
          <button className="btn-primary" disabled={busy || !audience || audience.total === 0} onClick={send}>{busy ? "Sending..." : "Send"}</button>
        </div>
      </div>
    </div>
  );
}
