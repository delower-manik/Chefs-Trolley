export function KpiCard({
  label,
  value,
  icon,
  trend,
}: {
  label: string;
  value: string;
  icon: string;
  trend?: string;
}) {
  return (
    <div className="card p-5 border-l-2 border-l-primary">
      <div className="flex justify-between items-start">
        <span className="text-sm text-text-secondary">{label}</span>
        <span className="material-symbols-outlined text-primary-fg text-[22px]">{icon}</span>
      </div>
      <div className="text-2xl font-extrabold mt-3 text-strong font-headline">{value}</div>
      {trend && <div className="text-xs text-success mt-2">{trend}</div>}
    </div>
  );
}

export function KpiCardSkeleton() {
  return (
    <div className="card p-5 animate-pulse">
      <div className="h-4 w-24 bg-surface-hover rounded" />
      <div className="h-7 w-16 bg-surface-hover rounded mt-4" />
      <div className="h-3 w-20 bg-surface-hover rounded mt-3" />
    </div>
  );
}
