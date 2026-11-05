export type EmploymentType = "full_time" | "part_time" | "contract";

export type Employee = {
  id: string;
  branchId: string;
  userId: string | null;
  name: string;
  phone: string;
  email: string | null;
  roleTitle: string;
  employmentType: EmploymentType;
  monthlySalary: number;
  hireDate: string | null;
  isActive: boolean;
};

export type AttendanceStatus = "present" | "absent" | "on_leave" | "half_day";

export type AttendanceRosterEntry = {
  employeeId: string;
  employeeName: string;
  roleTitle: string;
  date: string;
  status: AttendanceStatus | null;
  checkInTime: string | null;
  checkOutTime: string | null;
  notes: string | null;
};

export type AttendanceHistoryEntry = {
  id: string;
  date: string;
  status: AttendanceStatus;
  checkInTime: string | null;
  checkOutTime: string | null;
  notes: string | null;
};

export type SaveEmployeePayload = {
  branch_id?: string;
  name: string;
  phone: string;
  email?: string;
  role_title: string;
  employment_type?: EmploymentType;
  monthly_salary?: number;
  hire_date?: string;
  is_active?: boolean;
  user_id?: string | null;
};

export type MarkAttendancePayload = {
  employee_id: string;
  date: string;
  status: AttendanceStatus;
  check_in_time?: string;
  check_out_time?: string;
  notes?: string;
};
