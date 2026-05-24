import { useEffect } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuthStore } from '../../stores/authStore';

export default function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { token, user, loadUser } = useAuthStore();

  useEffect(() => {
    if (token && !user) loadUser();
  }, [token, user, loadUser]);

  if (!token) return <Navigate to="/login" replace />;
  if (!user) {
    // Show the branded loading screen instead of a flat text label —
    // first-paint after sign-in waits on `loadUser`, which can be 200-800ms
    // depending on the user's organization data.
    return (
      <div className="page-loading">
        <div className="page-loading-logo">S</div>
        <div className="text-sm text-slate-500 font-semibold">Loading your projects…</div>
        <div style={{ width: 180 }}><div className="progress-bar"><span /></div></div>
      </div>
    );
  }
  return <>{children}</>;
}
