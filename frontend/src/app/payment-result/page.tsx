"use client";

import { Suspense } from "react";
import { useSearchParams } from "next/navigation";

const TEXT: Record<string, { title: string; body: string }> = {
  paid: { title: "Payment received", body: "Thank you! The restaurant has been notified." },
  pending: { title: "Waiting for confirmation", body: "Your payment is being confirmed. You can close this page — the restaurant will see it as soon as it is confirmed." },
  failed: { title: "Payment did not go through", body: "No money was taken for this attempt. Please ask the staff to try again." },
  cancelled: { title: "Payment cancelled", body: "Nothing was charged." },
  expired: { title: "Payment expired", body: "This payment request timed out. Please ask the staff for a new one." },
  review: { title: "We are checking your payment", body: "Your payment needs a quick check by the restaurant. Please tell the staff." },
};

function Result() {
  const status = useSearchParams().get("status") ?? "pending";
  const t = TEXT[status] ?? TEXT.pending!;
  // This page only reflects what the URL says; the till's own screen (checked with the provider) is the source of truth.
  return (
    <main className="min-h-screen grid place-items-center p-6">
      <div className="card p-8 max-w-sm text-center space-y-2">
        <h1 className="text-xl font-bold text-strong">{t.title}</h1>
        <p className="text-sm text-text-secondary">{t.body}</p>
      </div>
    </main>
  );
}

export default function PaymentResultPage() {
  return (
    <Suspense>
      <Result />
    </Suspense>
  );
}
