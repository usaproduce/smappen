import { useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { authApi } from '../../api/auth';

export default function VerifyEmailPage() {
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const [status, setStatus] = useState<'pending' | 'ok' | 'err'>('pending');
  const [error, setError] = useState<string | null>(null);
  // React 18 StrictMode mounts effects twice in dev; the verification token
  // is single-use, so the second call would hit "Token already used" and the
  // user would see an erroneous error. Module-scoped ref lock guards against
  // both StrictMode and rapid back-button remounts.
  const attemptedFor = useRef<string | null>(null);

  useEffect(() => {
    if (attemptedFor.current === token) return;
    attemptedFor.current = token;
    let done = false;
    (async () => {
      if (!token) { setStatus('err'); setError('Missing token'); return; }
      try {
        await authApi.verifyEmail(token);
        if (!done) setStatus('ok');
      } catch (e: any) {
        if (!done) {
          setStatus('err');
          setError(e?.response?.data?.error ?? 'Verification failed');
        }
      }
    })();
    return () => { done = true; };
  }, [token]);

  return (
    <div className="min-h-screen flex items-center justify-center bg-slate-50 px-4">
      <div className="card w-full max-w-md text-center">
        <h1 className="font-extrabold text-2xl mb-1" style={{ color: '#1A1A2E' }}>Verify email</h1>
        {status === 'pending' && <p className="text-slate-600 mt-2">Confirming…</p>}
        {status === 'ok' && (
          <p className="text-emerald-700 bg-emerald-50 border border-emerald-200 rounded p-3 mt-3">
            Your email has been verified. You can close this tab or head to your projects.
          </p>
        )}
        {status === 'err' && (
          <p className="text-rose-700 bg-rose-50 border border-rose-200 rounded p-3 mt-3">{error}</p>
        )}
        <Link to="/" className="block mt-4 text-violet-700 font-semibold hover:underline">Go to Smappen →</Link>
      </div>
    </div>
  );
}
