"use client";

import { useAuth } from "@/lib/auth/auth-context";

type CanProps = {
  permission: string;
  children: React.ReactNode;
  fallback?: React.ReactNode;
};

/**
 * Gates rendering of a UI element by permission. This is a UX convenience
 * only -- hiding a button is not security (Non-Negotiable Rule #9). Every
 * mutating endpoint this button calls re-checks the same permission
 * server-side in the Laravel policy layer.
 */
export function Can({ permission, children, fallback = null }: CanProps) {
  const { hasPermission } = useAuth();
  if (!hasPermission(permission)) return <>{fallback}</>;
  return <>{children}</>;
}
