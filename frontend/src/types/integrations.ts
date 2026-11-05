export type PaymentGateway = {
  id: string;
  gatewayKey: string;
  displayName: string;
  isEnabled: boolean;
  isConfigured: boolean;
  online?: boolean;
  manual?: boolean;
  fields?: GatewayField[];
};

export type GatewayField = {
  key: string;
  label: string;
  secret: boolean;
  required: boolean;
  type?: "text" | "bool" | "textarea";
  isSet: boolean;
  value: string | null;
};

export type SavePaymentGatewayPayload = {
  is_enabled?: boolean;
  config?: Record<string, string>;
};

export type WebhookEvent = "order.created" | "order.cancelled" | "order.delivered" | "review.created" | "customer.created";

export type Webhook = {
  id: string;
  url: string;
  event: WebhookEvent;
  isActive: boolean;
  secret: string | null; // only populated in the response right after creation
  createdAt: string;
};

export type SaveWebhookPayload = {
  url: string;
  event: WebhookEvent;
  is_active?: boolean;
};

export type WifiAccessType = "open" | "voucher" | "time_limited";

export type WifiZone = {
  id: string;
  branchId: string;
  name: string;
  ssid: string | null;
  accessType: WifiAccessType;
  defaultSessionMinutes: number | null;
  isActive: boolean;
};

export type SaveWifiZonePayload = {
  name: string;
  ssid?: string;
  access_type?: WifiAccessType;
  default_session_minutes?: number;
  is_active?: boolean;
};

export type WifiVoucher = {
  id: string;
  zoneId: string;
  code: string;
  durationMinutes: number;
  isUsed: boolean;
  usedAt: string | null;
  createdAt: string;
};

export type GenerateVouchersPayload = {
  duration_minutes: number;
  count?: number;
};
