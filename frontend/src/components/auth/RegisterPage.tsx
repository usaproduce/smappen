import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { useAuthStore } from '../../stores/authStore';

export default function RegisterPage() {
  const navigate = useNavigate();
  const { register, isLoading } = useAuthStore();
  const [form, setForm] = useState({ name: '', email: '', password: '', organization_name: '' });

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (form.password.length < 8) return toast.error('Password must be at least 8 characters');
    try {
      await register(form);
      toast.success('Account created');
      navigate('/');
    } catch (err: any) {
      toast.error(err?.response?.data?.error ?? 'Registration failed');
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center px-4">
      <div className="w-full max-w-md">
        <div className="card">
          <h1 className="text-2xl font-bold mb-1" style={{ color: '#1e3a5f' }}>Create your account</h1>
          <p className="text-sm text-slate-500 mb-6">Start mapping territories in minutes.</p>
          <form onSubmit={onSubmit} className="space-y-4">
            <div>
              <label className="label">Name</label>
              <input className="input" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
            </div>
            <div>
              <label className="label">Email</label>
              <input className="input" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required />
            </div>
            <div>
              <label className="label">Password</label>
              <input className="input" type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} required minLength={8} />
            </div>
            <div>
              <label className="label">Organization (optional)</label>
              <input className="input" value={form.organization_name} onChange={(e) => setForm({ ...form, organization_name: e.target.value })} />
            </div>
            <button type="submit" className="btn btn-primary w-full justify-center" disabled={isLoading}>
              {isLoading ? 'Creating…' : 'Create account'}
            </button>
          </form>
          <div className="mt-4 text-sm text-center text-slate-500">
            Already have one? <Link to="/login" className="font-semibold" style={{ color: 'var(--brand)' }}>Sign in</Link>
          </div>
        </div>
      </div>
    </div>
  );
}
