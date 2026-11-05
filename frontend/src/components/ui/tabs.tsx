"use client";

type TabsProps<T extends string> = {
  label: string;
  tabs: { id: T; label: string }[];
  active: T;
  onChange: (id: T) => void;
};

/** Simple accessible tab strip; the caller renders the matching panel. */
export function Tabs<T extends string>({ label, tabs, active, onChange }: TabsProps<T>) {
  return (
    <div role="tablist" aria-label={label} className="flex flex-wrap gap-2">
      {tabs.map((t) => (
        <button
          key={t.id}
          role="tab"
          aria-selected={active === t.id}
          onClick={() => onChange(t.id)}
          className={
            active === t.id ? "bg-primary text-on-primary rounded-lg px-4 py-2 text-sm font-semibold" : "btn-secondary !py-2"
          }
        >
          {t.label}
        </button>
      ))}
    </div>
  );
}
