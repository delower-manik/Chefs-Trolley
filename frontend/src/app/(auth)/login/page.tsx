"use client";

import { useState, type FormEvent } from "react";
import { useAuth } from "@/lib/auth/auth-context";
import { ApiError } from "@/lib/api/client";

export default function LoginPage() {
  const { login, verifyMfa } = useAuth();
  const [challenge, setChallenge] = useState<string | null>(null);
  const [code, setCode] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      if (challenge) {
        await verifyMfa(challenge, code);
      } else {
        const next = await login({ email, password });
        if (next) {
          setChallenge(next.challenge);
          setPassword("");
        }
      }
    } catch (err) {
      // An expired or used-up challenge sends the person back to the password step.
      if (err instanceof ApiError && err.status === 401 && challenge) {
        setChallenge(null);
        setCode("");
      }
      setError(
        err instanceof ApiError
          ? err.message
          : "Couldn't reach the server. Check your connection and try again."
      );
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="min-h-screen w-full grid place-items-center bg-bg px-4">
      <div className="w-full max-w-sm">
        <div className="text-center mb-8">
          <div className="text-3xl font-extrabold text-strong font-headline">
            Chefs <span className="text-primary-fg">Trolley</span>
          </div>
          <p className="text-sm text-text-secondary mt-2">Sign in to the restaurant management console</p>
        </div>

        <form onSubmit={handleSubmit} className="card p-6 space-y-4">
          {error && (
            <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2" role="alert">
              {error}
            </div>
          )}

          {challenge ? (
            <div>
              <label htmlFor="code" className="block text-xs font-medium text-text-secondary mb-1.5">
                Authenticator code or recovery code
              </label>
              <input
                id="code"
                required
                autoFocus
                inputMode="text"
                autoComplete="one-time-code"
                value={code}
                onChange={(e) => setCode(e.target.value)}
                className="input-field tracking-widest"
                placeholder="123456"
              />
              <p className="text-[11px] text-text-muted mt-1.5">Open your authenticator app and enter the 6-digit code. Lost your phone? Use one of your recovery codes.</p>
              <button type="button" className="text-xs text-primary-fg mt-2" onClick={() => { setChallenge(null); setCode(""); setError(null); }}>
                Back
              </button>
            </div>
          ) : (
            <>
          <div>
                <label htmlFor="email" className="block text-xs font-medium text-text-secondary mb-1.5">
                  Email
                </label>
                <input
                  id="email"
                  type="email"
                  required
                  autoComplete="username"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="input-field"
                  placeholder="you@chefstrolley.com"
                />
              </div>

              <div>
                <label htmlFor="password" className="block text-xs font-medium text-text-secondary mb-1.5">
                  Password
                </label>
                <input
                  id="password"
                  type="password"
                  required
                  autoComplete="current-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className="input-field"
                  placeholder="••••••••"
                />
              </div>
            </>
          )}

          <button type="submit" disabled={submitting} className="btn-primary w-full py-2.5">
            {submitting ? "Signing in..." : challenge ? "Verify" : "Sign in"}
          </button>
        </form>

        <p className="text-center text-xs text-text-muted mt-6">
          Chefs Trolley Restaurant OS · Chuadanga, Bangladesh
        </p>
      </div>
    </div>
  );
}
