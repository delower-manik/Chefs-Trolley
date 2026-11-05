"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { useApp } from "@/components/Providers";

export default function Index() {
  const { employee, home, t } = useApp();
  const router = useRouter();
  useEffect(() => { if (employee) router.replace(home()); }, [employee, home, router]);
  return <p>{t("loading")}</p>;
}
