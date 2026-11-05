// Response/payload shapes for Users, Roles, Branches and Activity Logs
// (backend: UserController, RoleController, BranchController, ActivityLogController).

export type Paged<T> = {
  data: T[];
  meta: { currentPage: number; perPage: number; total: number; lastPage: number };
};

export type RoleRef = { id: string; name: string };
export type BranchRef = { id: string; name: string; code: string };

export type AdminUser = {
  id: string;
  name: string;
  email: string;
  isActive: boolean;
  activeBranchId: string | null;
  roles: RoleRef[];
  branches: BranchRef[];
  /** True for the signed-in admin's own row -- the API refuses self-lockout actions. */
  isSelf: boolean;
  createdAt: string;
};

export type UserFilters = { q?: string; status?: "active" | "inactive" | ""; page?: number };

export type CreateUserPayload = {
  name: string;
  email: string;
  password: string;
  role_ids: string[];
  branch_ids: string[];
  active_branch_id?: string | null;
  is_active?: boolean;
};

export type UpdateUserPayload = Partial<Omit<CreateUserPayload, "password">>;

/** What the signed-in admin is allowed to hand out (drives the form's pickers). */
export type AssignableOptions = {
  roles: { id: string; name: string; permissions: string[] }[];
  branches: BranchRef[];
};

export type AdminRole = {
  id: string;
  name: string;
  description: string | null;
  permissions: string[];
  userCount: number;
  /** Built-in (Super Admin): cannot be edited or deleted. */
  isSystem: boolean;
  /** Whether *this* admin may edit/delete it (they must hold every permission in it). */
  editable: boolean;
};

export type SaveRolePayload = { name: string; description?: string | null; permissions: string[] };

export type PermissionItem = { name: string; description: string | null; grantable: boolean };

export type Branch = {
  id: string;
  name: string;
  code: string;
  address: string | null;
  phone: string | null;
  timezone: string;
  isActive: boolean;
  userCount: number;
};

export type SaveBranchPayload = {
  name?: string;
  code?: string;
  address?: string | null;
  phone?: string | null;
  timezone?: string;
};

export type AuditLogEntry = {
  id: string;
  createdAt: string;
  actorId: string | null;
  actorName: string | null;
  action: string;
  subjectType: string;
  subjectId: string;
  branchId: string | null;
  changes: Record<string, unknown> | null;
  ipAddress: string | null;
};

export type ActivityLogFilters = {
  q?: string;
  category?: string;
  action?: string;
  from?: string;
  to?: string;
  page?: number;
};
