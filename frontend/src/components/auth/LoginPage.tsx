import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { useAuthStore } from '../../stores/authStore';

export default function LoginPage() {
  const navigate = useNavigate();
  const { login, isLoading } = useAuthStore();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    try {
      await login(email, password);
      toast.success('Welcome back');
      navigate('/');
    } catch (err: any) {
      toast.error(err?.response?.data?.error ?? 'Login failed');
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center px-4">
      <div className="w-full max-w-md">
        <div className="card">
          <h1 className="text-2xl font-bold mb-1" style={{ color: '#1e3a5f' }}>Smappen</h1>
          <p className="text-sm text-slate-500 mb-6">Sign in to your account</p>
          <form onSubmit={onSubmit} className="space-y-4">
            <div>
              <label className="label">Email</label>
              <input className="input" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus />
            </div>
            <div>
              <div className="flex items-center justify-between">
                <label className="label">Password</label>
                <Link to="/forgot-password" className="text-xs font-semibold text-violet-700 hover:underline">Forgot password?</Link>
              </div>
              <input className="input" type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />
            </div>
            <button type="submit" className="btn btn-primary w-full justify-center" disabled={isLoading}>
              {isLoading ? 'Signing in…' : 'Sign in'}
            </button>
          </form>
          <div className="mt-4 text-sm text-center text-slate-500">
            New here? <Link to="/register" className="font-semibold" style={{ color: 'var(--brand)' }}>Create an account</Link>
          </div>
        </div>
      </div>
    </div>
  );
}
