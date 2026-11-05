"use client";

import { useEffect } from "react";
import { usePathname } from "next/navigation";
import { useAuth } from "@/lib/auth/auth-context";
import { NavList } from "@/components/layout/nav-list";

function Brand() {
  return (
    <div className="p-5 border-b border-border">
      <div className="text-2xl font-extrabold text-strong font-headline">
        Chefs <span className="text-primary-fg">Trolley</span>
      </div>
      <div className="text-xs text-text-muted mt-1">Restaurant OS Admin</div>
    </div>
  );
}

function BranchFooter() {
  const { user } = useAuth();
  if (!user?.activeBranch) return null;
  return (
    <div className="p-4 border-t border-border text-xs text-text-secondary flex items-center gap-2">
      <span className="w-2 h-2 rounded-full bg-success" aria-hidden />
      {user.activeBranch.name}
    </div>
  );
}

/** Desktop: fixed sidebar. Below md: a slide-in drawer opened from the header's menu button. */
export function Sidebar({ open, onClose }: { open: boolean; onClose: () => void }) {
  const pathname = usePathname();

  // Close after navigating, and on Escape.
  useEffect(() => { onClose(); }, [pathname]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open, onClose]);

  return (
    <>
      <aside className="w-sidebar fixed inset-y-0 left-0 bg-surface-raised border-r border-border hidden md:flex flex-col z-40">
        <Brand />
        <NavList />
        <BranchFooter />
      </aside>

      {open && (
        <div className="md:hidden fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="Menu">
          <button className="absolute inset-0 bg-black/60" aria-label="Close menu" onClick={onClose} />
          <aside className="absolute inset-y-0 left-0 w-[min(85vw,18rem)] bg-surface-raised border-r border-border flex flex-col">
            <Brand />
            <NavList onNavigate={onClose} />
            <BranchFooter />
          </aside>
        </div>
      )}
    </>
  );
}
