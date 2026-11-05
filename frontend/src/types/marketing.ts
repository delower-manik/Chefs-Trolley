// Phase 15 — gift cards, customer segments, campaigns.

export type GiftCardStatus = "active" | "used" | "expired" | "void";

export type GiftCard = {
  id: string;
  last4: string;
  initialValue: number;
  balance: number;
  status: GiftCardStatus;
  expiresAt: string | null;
  customerId: string | null;
  customerName: string | null;
  recipientName: string | null;
  note: string | null;
  soldMethod: string | null;
  createdAt: string;
};

export type GiftCardEntry = {
  id: string;
  kind: "issue" | "redeem" | "refund" | "adjust" | "void";
  amount: number;
  balanceAfter: number;
  orderId: string | null;
  reason: string | null;
  createdAt: string;
};

export type GiftCardList = {
  data: GiftCard[];
  meta: { currentPage: number; perPage: number; total: number; lastPage: number; outstandingBalance: number };
};

export type GiftCardLookup = GiftCard & { usable: boolean; problem: string | null };

export type IssueGiftCardPayload = {
  value: number;
  sold_method: "cash" | "card" | "mobile_banking" | "other";
  sold_reference?: string;
  customer_id?: string;
  recipient_name?: string;
  expires_at?: string;
  note?: string;
};

export type RuleOp = "gte" | "lte" | "eq";
export type SegmentRule = { field: string; op: RuleOp; value: number | boolean | string };
export type SegmentField = { key: string; type: "number" | "bool"; label: string };

export type Segment = {
  id: string;
  name: string;
  description: string | null;
  match: "all" | "any";
  rules: SegmentRule[];
  size: number;
  createdAt: string;
};

export type SegmentPreview = {
  matching: number;
  optedOut: number;
  reachable: { sms: number; email: number; push: number };
  sample: { id: string; name: string }[];
};

export type CampaignStatus = "draft" | "scheduled" | "sending" | "sent" | "cancelled";
export type Channel = "push" | "sms" | "email";

export type Campaign = {
  id: string;
  name: string;
  segmentId: string | null;
  segmentName: string | null;
  title: string;
  body: string;
  channels: Channel[];
  status: CampaignStatus;
  scheduledAt: string | null;
  sentAt: string | null;
  audienceSize: number;
  queued: Record<Channel, number> | null;
  createdAt: string;
  deliveries?: Record<string, Record<string, number>>;
};

export type CampaignAudience = { total: number; sms: number; email: number; push: number };

export type SaveCampaignPayload = {
  name: string;
  segment_id: string | null;
  title: string;
  body: string;
  channels: Channel[];
  scheduled_at?: string | null;
};
