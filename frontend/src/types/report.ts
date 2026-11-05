// Response shapes for the read-only Reports & Analytics endpoints
// (backend: ReportController). Money values are plain numbers in BDT.

export type DateRange = { from: string; to: string };

export type SalesReport = DateRange & {
  totals: {
    orders: number;
    revenue: number;
    averageOrderValue: number;
    discounts: number;
    tax: number;
    deliveryFees: number;
  };
  byDay: { date: string; orders: number; revenue: number }[];
  bySource: { source: string; orders: number; revenue: number }[];
};

export type ProductsReport = DateRange & {
  products: { name: string; quantity: number; revenue: number }[];
};

export type CategoriesReport = DateRange & {
  categories: { category: string; quantity: number; revenue: number }[];
};

export type DeliveryReport = DateRange & {
  totals: {
    assignments: number;
    delivered: number;
    failed: number;
    inProgress: number;
    /** Percent of finished (delivered + failed) attempts that were delivered; null when none finished. */
    successRate: number | null;
    /** Mean minutes from rider assignment to delivery; null when nothing can be averaged. */
    avgDeliveryMinutes: number | null;
  };
  byRider: { rider: string; assigned: number; delivered: number; failed: number }[];
};

export type CustomersReport = DateRange & {
  newCustomers: number;
  topSpenders: { customerId: string; name: string; phone: string | null; orders: number; spent: number }[];
  loyalty: { pointsEarned: number; pointsRedeemed: number };
};

export type BranchesReport = DateRange & {
  branches: {
    branchId: string;
    name: string;
    code: string;
    orders: number;
    revenue: number;
    expenses: number;
    netProfit: number;
  }[];
};
