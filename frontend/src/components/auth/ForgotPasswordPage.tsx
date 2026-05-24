import { useState } from 'react';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { authApi } from '../../api/auth';

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      await authApi.requestReset(email);
      setSent(true);
    } catch (err: any) {
      toast.error(err?.response?.data?.error ?? 'Something went wrong');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-slate-50 px-4">
      <div className="card w-full max-w-md">
        <h1 className="font-extrabold text-2xl mb-1" style={{ color: '#1A1A2E' }}>Reset your password</h1>
        {sent ? (
          <p className="text-sm text-slate-600">
            If an account with that email exists, we've sent a reset link. The link expires in 1 hour.
            Check your inbox (and spam folder).
          </p>
        ) : (
          <form onSubmit={submit} className="space-y-3">
            <p className="text-sm text-slate-600">Enter the email on your account.</p>
            <input
              className="input"
              type="email"
              autoFocus
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="you@company.com"
            />
            <button className="btn btn-primary w-full" disabled={busy}>
              {busy ? 'Sending…' : 'Send reset link'}
            </button>
          </form>
        )}
        <div className="mt-4 text-sm">
          <Link to="/login" className="text-violet-700 font-semibold hover:underline">Back to sign in</Link>
        </div>
      </div>
    </div>
  );
}
