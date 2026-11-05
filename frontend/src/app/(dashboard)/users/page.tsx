"use client";

import { useState } from "react";
import { Tabs } from "@/components/ui/tabs";
import { UsersPanel } from "./users-panel";
import { RolesPanel } from "./roles-panel";

type TabId = "users" | "roles";

export default function UsersAndRolesPage() {
  const [tab, setTab] = useState<TabId>("users");

  return (
    <section className="max-w-6xl mx-auto space-y-5">
      <div>
        <h2 className="text-2xl font-bold text-strong">Users &amp; Roles</h2>
        <p className="text-sm text-text-secondary">
          Staff login accounts and what each role is allowed to do. You can only hand out access you hold yourself,
          and every change is recorded in the activity log.
        </p>
      </div>

      <Tabs
        label="Users and roles"
        tabs={[
          { id: "users", label: "Users" },
          { id: "roles", label: "Roles & permissions" },
        ]}
        active={tab}
        onChange={setTab}
      />

      <div role="tabpanel">{tab === "users" ? <UsersPanel /> : <RolesPanel />}</div>
    </section>
  );
}
