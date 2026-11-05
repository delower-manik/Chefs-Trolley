"use client";

import { useState } from "react";
import { useReviews, publishReview, unpublishReview, deleteReview } from "@/hooks/use-growth";
import { formatDateTime } from "@/lib/format";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";

const FILTERS = [
  { label: "All", value: undefined },
  { label: "Published", value: true },
  { label: "Pending", value: false },
] as const;

export default function ReviewsPage() {
  const [filter, setFilter] = useState<boolean | undefined>(undefined);
  const { reviews, isLoading, error, refresh } = useReviews(filter);

  return (
    <section className="max-w-4xl mx-auto space-y-5">
      <div>
        <h2 className="text-2xl font-bold text-strong">Reviews</h2>
        <p className="text-sm text-text-secondary">New reviews stay hidden from the public site until you publish them.</p>
      </div>

      <div className="flex gap-2">
        {FILTERS.map((f) => (
          <button
            key={f.label}
            onClick={() => setFilter(f.value)}
            className={`px-3 py-1.5 rounded-full text-sm ${
              filter === f.value ? "bg-primary text-on-primary" : "bg-surface-hover text-text-secondary"
            }`}
          >
            {f.label}
          </button>
        ))}
      </div>

      <div className="space-y-3">
        {error ? (
          <div className="card p-10 text-center text-sm text-text-secondary">Couldn&apos;t load reviews.</div>
        ) : isLoading ? (
          Array.from({ length: 3 }).map((_, i) => <div key={i} className="h-24 bg-surface-hover rounded-card animate-pulse" />)
        ) : reviews.length === 0 ? (
          <div className="card p-10 text-center text-sm text-text-secondary">No reviews here yet.</div>
        ) : (
          reviews.map((review) => (
            <div key={review.id} className="card p-4">
              <div className="flex justify-between items-start">
                <div>
                  <div className="flex items-center gap-2">
                    <span className="font-bold text-strong">{review.reviewerName ?? "Guest"}</span>
                    <span className="text-warning">{"★".repeat(review.rating)}{"☆".repeat(5 - review.rating)}</span>
                  </div>
                  <span className="text-xs text-text-secondary">{formatDateTime(review.createdAt)}</span>
                </div>
                <span className={`badge ${review.isPublished ? "bg-success/15 text-success" : "bg-warning/15 text-warning"}`}>
                  {review.isPublished ? "Published" : "Pending"}
                </span>
              </div>
              {review.comment && <p className="text-sm text-text-secondary mt-2">{review.comment}</p>}
              <Can permission="reviews.moderate">
                <div className="flex gap-3 mt-3">
                  {review.isPublished ? (
                    <button
                      className="text-xs text-warning font-medium"
                      onClick={async () => {
                        try {
                          await unpublishReview(review.id);
                          refresh();
                        } catch (err) {
                          window.alert(err instanceof ApiError ? err.message : "Couldn't update this review.");
                        }
                      }}
                    >
                      Unpublish
                    </button>
                  ) : (
                    <button
                      className="text-xs text-success font-medium"
                      onClick={async () => {
                        try {
                          await publishReview(review.id);
                          refresh();
                        } catch (err) {
                          window.alert(err instanceof ApiError ? err.message : "Couldn't update this review.");
                        }
                      }}
                    >
                      Publish
                    </button>
                  )}
                  <button
                    className="text-xs text-danger font-medium"
                    onClick={async () => {
                      if (!window.confirm("Delete this review?")) return;
                      try {
                        await deleteReview(review.id);
                        refresh();
                      } catch (err) {
                        window.alert(err instanceof ApiError ? err.message : "Couldn't delete this review.");
                      }
                    }}
                  >
                    Delete
                  </button>
                </div>
              </Can>
            </div>
          ))
        )}
      </div>
    </section>
  );
}
