import { useEffect, useState } from 'react';
import { Navigate, Link } from 'react-router-dom';
import { useAuthStore } from '../../stores/authStore';

/**
 * Carafe admin route guard. Hard 403 unless the logged-in user has
 * role 'admin' or 'owner' — matches backend Middleware::requireRole
 * (mirror the gate so we never paint UI for a user the backend would
 * 403 anyway).
 *
 * Intentionally NOT linked from the global navbar — the user is
 * expected to navigate directly via /admin/carafe.
 *
 * Build stamp on console so a user can confirm the latest bundle is
 * loaded (look for 'admin-only-route v2' in DevTools).
 */
export default function AdminOnlyRoute({ children }: { children: React.ReactNode }) {
  const { token, user, loadUser } = useAuthStore();
  const [stalled, setStalled] = useState(false);

  // Build stamp so you can verify the deployed bundle is the new one.
  // If you see this in console, you ARE on the post-fix version.
  // eslint-disable-next-line no-console
  useEffect(() => { console.log('[carafe] admin-only-route v2 — token?', !!token, 'user?', !!user); }, [token, user]);

  // Trigger user fetch when we have a token but no user loaded yet —
  // matches ProtectedRoute's pattern so deep-linking to /admin/carafe
  // doesn't sit forever on the loading screen.
  useEffect(() => {
    if (token && !user) loadUser();
  }, [token, user, loadUser]);

  // Safety net: if loadUser is hanging (auth endpoint slow / unreachable
  // / network blocked), don't let the user sit on a blank loading
  // screen indefinitely.
  useEffect(() => {
    if (!token || user) return;
    const id = setTimeout(() => setStalled(true), 8000);
    return () => clearTimeout(id);
  }, [token, user]);

  if (!token) return <Navigate to="/login" replace />;

  if (!user && stalled) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-slate-50 p-6">
        <div className="max-w-md w-full text-center">
          <h1 className="text-xl font-bold text-slate-900">Auth check is taking longer than expected</h1>
          <p className="text-slate-600 mt-2 text-sm">
            We couldn't load your account in 8 seconds. The /api/auth/me request is probably
            slow, blocked, or hitting a stale bundle.
          </p>
          <div className="mt-5 flex items-center justify-center gap-2">
            <button
              className="btn btn-primary h-9 px-4 text-sm"
              onClick={() => {
                // Hard reload to bust any stale bundle/SW cache.
                if ('serviceWorker' in navigator) {
                  navigator.serviceWorker.getRegistrations().then((rs) => rs.forEach((r) => r.unregister()));
                }
                location.reload();
              }}
            >
              Hard refresh
            </button>
            <Link to="/login" className="btn h-9 px-4 text-sm bg-slate-100 text-slate-700 hover:bg-slate-200">
              Re-login
            </Link>
          </div>
          <p className="text-[11px] text-slate-400 mt-6">Open DevTools → Console — look for <code>[carafe] admin-only-route v2</code> to confirm the new bundle is loaded.</p>
        </div>
      </div>
    );
  }

  if (!user) {
    return (
      <div className="page-loading">
        <div className="page-loading-logo">S</div>
        <div className="text-sm text-slate-500 font-semibold">Checking your account…</div>
      </div>
    );
  }

  if (user.role !== 'admin' && user.role !== 'owner') {
    return (
      <div className="min-h-screen flex items-center justify-center bg-slate-50">
        <div className="max-w-md text-center p-8">
          <div className="text-5xl mb-4">403</div>
          <h1 className="text-xl font-bold text-slate-900">Admins only</h1>
          <p className="text-slate-600 mt-2 text-sm">
            The Carafe admin surface is restricted to organization admins and owners.
            Your current role is <code className="text-[11px] bg-slate-100 px-1 py-0.5 rounded">{user.role}</code>.
          </p>
          <p className="text-xs text-slate-500 mt-4">
            To bump your role: <code className="text-[11px] bg-slate-100 px-1 py-0.5 rounded">UPDATE users SET role='admin' WHERE email='{user.email}'</code>
          </p>
        </div>
      </div>
    );
  }

  return <>{children}</>;
}
