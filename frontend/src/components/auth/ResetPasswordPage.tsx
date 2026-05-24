import { useState } from 'react';
import { Link, useSearchParams, useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { authApi } from '../../api/auth';

export default function ResetPasswordPage() {
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const nav = useNavigate();
  const [pwd, setPwd] = useState('');
  const [confirm, setConfirm] = useState('');
  const [busy, setBusy] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (pwd !== confirm) { toast.error('Passwords do not match'); return; }
    if (pwd.length < 8) { toast.error('At least 8 characters'); return; }
    setBusy(true);
    try {
      await authApi.resetPassword(token, pwd);
      toast.success('Password reset — please sign in');
      nav('/login');
    } catch (err: any) {
      toast.error(err?.response?.data?.error ?? 'Reset failed — link may be expired');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-slate-50 px-4">
      <div className="card w-full max-w-md">
        <h1 className="font-extrabold text-2xl mb-1" style={{ color: '#1A1A2E' }}>Set a new password</h1>
        {!token ? (
          <p className="text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded p-2">
            This link is missing a token. Request a new reset link from the sign-in page.
          </p>
        ) : (
          <form onSubmit={submit} className="space-y-3 mt-2">
            <input
              className="input"
              type="password"
              required
              value={pwd}
              autoFocus
              onChange={(e) => setPwd(e.target.value)}
              placeholder="New password (8+ chars)"
            />
            <input
              className="input"
              type="password"
              required
              value={confirm}
              onChange={(e) => setConfirm(e.target.value)}
              placeholder="Confirm new password"
            />
            <button className="btn btn-primary w-full" disabled={busy}>
              {busy ? 'Saving…' : 'Reset password'}
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
