// Phase 14 — notification and webhook delivery logs.

export type NotifyChannel = "push" | "sms" | "email";

export type NotificationDelivery = {
  id: string;
  channel: NotifyChannel;
  recipient: string; // masked by the server
  subject: string | null;
  purpose: string;
  status: "queued" | "sent" | "failed";
  provider: string | null;
  attempts: number;
  error: string | null;
  sentAt: string | null;
  createdAt: string;
};

export type ChannelSetup = { configured: boolean; driver?: string };

export type DeliveryLogResponse = {
  data: NotificationDelivery[];
  channels: Record<NotifyChannel, ChannelSetup>;
};

export type SendResult = { queued: Record<NotifyChannel, number> };

export type WebhookDeliveryRow = {
  id: string;
  event: string;
  eventId: string;
  status: "queued" | "sent" | "failed";
  attempts: number;
  responseStatus: number | null;
  responseExcerpt: string | null;
  error: string | null;
  nextAttemptAt: string | null;
  deliveredAt: string | null;
  createdAt: string;
};
