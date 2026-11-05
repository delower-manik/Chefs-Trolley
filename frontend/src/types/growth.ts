export type PromotionType = "percentage" | "fixed";

export type Promotion = {
  id: string;
  branchId: string | null;
  code: string;
  name: string;
  type: PromotionType;
  value: number;
  minOrderAmount: number;
  maxDiscountAmount: number | null;
  usageLimit: number | null;
  usageLimitPerCustomer: number | null;
  usedCount: number;
  startsAt: string | null;
  expiresAt: string | null;
  isActive: boolean;
};

export type SavePromotionPayload = {
  branch_id?: string;
  code: string;
  name: string;
  type: PromotionType;
  value: number;
  min_order_amount?: number;
  max_discount_amount?: number;
  usage_limit?: number;
  usage_limit_per_customer?: number;
  starts_at?: string;
  expires_at?: string;
  is_active?: boolean;
};

export type Review = {
  id: string;
  branchId: string;
  orderId: string | null;
  customerId: string | null;
  reviewerName: string | null;
  rating: number;
  comment: string | null;
  isPublished: boolean;
  createdAt: string;
};

export type SaveReviewPayload = {
  order_id?: string;
  customer_id?: string;
  reviewer_name?: string;
  rating: number;
  comment?: string;
};

export type NotificationAudience = "all_customers" | "specific_customer";
export type NotificationStatus = "draft" | "sent";

export type AppNotification = {
  id: string;
  branchId: string | null;
  title: string;
  body: string;
  audience: NotificationAudience;
  customerId: string | null;
  customerName: string | null;
  status: NotificationStatus;
  sentAt: string | null;
  createdAt: string;
};

export type SaveNotificationPayload = {
  title: string;
  body: string;
  audience: NotificationAudience;
  customer_id?: string;
};
