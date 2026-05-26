import { useEffect } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuthStore } from '../../stores/authStore';

/**
 * Carafe admin route guard. Hard 403 unless the logged-in user has
 * role 'admin' or 'owner' — matches backend Middleware::requireRole
 * (mirror the gate so we never paint UI for a user the backend would
 * 403 anyway).
 *
 * Intentionally NOT linked from the global navbar — the user is
 * expected to navigate directly via /admin/carafe.
 */
export default function AdminOnlyRoute({ children }: { children: React.ReactNode }) {
  const { token, user, loadUser } = useAuthStore();

  // Trigger user fetch when we have a token but no user loaded yet —
  // matches ProtectedRoute's pattern so deep-linking to /admin/carafe
  // doesn't sit forever on the loading screen.
  useEffect(() => {
    if (token && !user) loadUser();
  }, [token, user, loadUser]);

  if (!token) return <Navigate to="/login" replace />;
  if (!user) {
    return (
      <div className="page-loading">
        <div className="page-loading-logo">S</div>
        <div className="text-sm text-slate-500 font-semibold">Loading…</div>
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
          </p>
        </div>
      </div>
    );
  }
  return <>{children}</>;
}
