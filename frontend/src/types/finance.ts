export type PayrollStatus = "draft" | "finalized" | "paid";

export type PayrollEntry = {
  id: string;
  employeeId: string;
  employeeName: string | null;
  baseSalary: number;
  absentDays: number;
  deductions: number;
  bonuses: number;
  netPay: number;
};

export type PayrollPeriod = {
  id: string;
  branchId: string;
  periodStart: string;
  periodEnd: string;
  status: PayrollStatus;
  entryCount: number;
  totalNetPay: number;
  finalizedAt: string | null;
  paidAt: string | null;
  entries?: PayrollEntry[];
};

export type ExpenseCategory = {
  id: string;
  branchId: string | null;
  name: string;
};

export type Expense = {
  id: string;
  branchId: string;
  categoryId: string;
  categoryName: string | null;
  amount: number;
  description: string | null;
  expenseDate: string;
  isSystemGenerated: boolean;
  createdAt: string;
};

export type AccountingSummary = {
  from: string;
  to: string;
  totalRevenue: number;
  totalExpenses: number;
  netProfit: number;
  expensesByCategory: { category: string; amount: number }[];
};

export type CreatePayrollPeriodPayload = {
  period_start: string;
  period_end: string;
};

export type SaveExpensePayload = {
  category_id: string;
  amount: number;
  description?: string;
  expense_date: string;
};
