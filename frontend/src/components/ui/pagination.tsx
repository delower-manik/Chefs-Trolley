"use client";

type PaginationProps = {
  page: number;
  lastPage: number;
  total: number;
  onChange: (page: number) => void;
};

export function Pagination({ page, lastPage, total, onChange }: PaginationProps) {
  if (total === 0) return null;
  return (
    <div className="flex items-center justify-between p-3 border-t border-border text-xs text-text-secondary">
      <span>
        {total} {total === 1 ? "entry" : "entries"} · page {page} of {lastPage}
      </span>
      <div className="flex gap-2">
        <button className="btn-secondary !py-1.5" disabled={page <= 1} onClick={() => onChange(page - 1)}>
          Previous
        </button>
        <button className="btn-secondary !py-1.5" disabled={page >= lastPage} onClick={() => onChange(page + 1)}>
          Next
        </button>
      </div>
    </div>
  );
}
