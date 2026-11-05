export type AccountInfo = {
  name: string;
  email: string;
  mfaEnabled: boolean;
  recoveryCodesLeft: number;
  passwordChangedAt: string | null;
  lastLoginAt: string | null;
  lastLoginIp: string | null;
};

export type SessionInfo = { id: string; ip: string | null; device: string | null; startedAt: string; lastActiveAt: string; current: boolean };

export type BackupRun = {
  id: string;
  status: "running" | "ok" | "failed" | "pruned";
  trigger: "schedule" | "manual";
  filename: string | null;
  sizeBytes: number | null;
  database: string | null;
  includesMedia: boolean;
  offsiteDisk: string | null;
  offsiteOk: boolean | null;
  error: string | null;
  verifiedAt: string | null;
  startedAt: string | null;
  finishedAt: string | null;
};

export type BackupMeta = {
  lastOkAt: string | null;
  keyConfigured: boolean;
  offsiteDisk: string | null;
  includeMedia: boolean;
  retention: { daily: number; weekly: number; monthly: number };
};
