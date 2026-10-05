import { useEffect } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuthStore } from '../../stores/authStore';

export default function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { token, user, loadUser } = useAuthStore();
  const { pathname } = useLocation();

  useEffect(() => {
    if (token && !user) loadUser();
  }, [token, user, loadUser]);

  if (!token) return <Navigate to="/login" replace />;
  if (!user) {
    // Truck Planner routes wait on `loadUser` with the same plain skeleton
    // their layout shows while a page loads: two empty bars where the top
    // nav and the sub-nav will be, one card and three rows. No logo tile, no
    // progress bar, no text. Written inline on purpose: this file is in the
    // main bundle and must not import anything from truck code.
    if (pathname === '/truck' || pathname.startsWith('/truck/')) {
      return (
        <div className="min-h-screen" style={{ background: 'var(--bg)' }} aria-busy="true">
          <div
            aria-hidden="true"
            className="border-b"
            style={{ height: 49, background: 'var(--nav-bg)', borderColor: 'var(--nav-border)' }}
          />
          <div
            aria-hidden="true"
            className="border-b bg-white h-[57px] md:h-[49px]"
            style={{ borderColor: 'var(--nav-border)' }}
          />
          <div className="max-w-7xl mx-auto px-4 md:px-6 py-4 md:py-6 space-y-4">
            <div className="skeleton rounded-xl" style={{ height: 96 }} />
            <div className="skeleton rounded-xl" style={{ height: 72 }} />
            <div className="skeleton rounded-xl" style={{ height: 72 }} />
            <div className="skeleton rounded-xl" style={{ height: 72 }} />
          </div>
        </div>
      );
    }
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
