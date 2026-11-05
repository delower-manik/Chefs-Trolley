export type Permission = string; // e.g. "orders.view", "menu.manage"

export type Role = {
  id: string;
  name: string;
  permissions: Permission[];
};

export type Branch = {
  id: string;
  name: string;
  code: string;
};

export type AuthUser = {
  id: string;
  name: string;
  email: string;
  mfaEnabled?: boolean;
  roles: Role[];
  permissions: Permission[]; // flattened, resolved server-side
  activeBranch: Branch | null;
  branches: Branch[];
};

export type LoginPayload = {
  email: string;
  password: string;
};

export type AuthTokens = {
  accessToken: string;
  refreshToken: string;
  expiresAt: string; // ISO timestamp
};

export type AuthSession = {
  user: AuthUser;
  tokens: AuthTokens;
};

/** Password was right but the account has two-step sign-in: answer `challenge` with a code to get a session. */
export type MfaChallenge = { mfaRequired: true; challenge: string };
