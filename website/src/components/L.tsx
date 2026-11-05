"use client";

import Link from "next/link";
import type { ComponentProps } from "react";
import { useApp } from "./providers";

/** Internal link that keeps the visitor in their language: <L href="/menu"> becomes /bn/menu. */
export default function L({ href, ...rest }: Omit<ComponentProps<typeof Link>, "href"> & { href: string }) {
  const { path } = useApp();
  return <Link href={path(href)} {...rest} />;
}
