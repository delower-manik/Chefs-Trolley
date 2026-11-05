"use client";

import L from "@/components/L";
import { useApp } from "./providers";

export default function RequireLogin({ children, next }: { children: React.ReactNode; next: string }) {
  const { customer, authReady, t } = useApp();
  if (!authReady) return <p className="text-soft">{t("common.loading")}</p>;
  if (!customer) return (<div className="card text-center"><L href={`/login?next=${encodeURIComponent(next)}`} className="btn">{t("nav.signin")}</L></div>);
  return <>{children}</>;
}
