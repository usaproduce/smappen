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
    return (
      <div className="flex items-center justify-center h-screen">
        <div className="text-slate-500">Loading…</div>
      </div>
    );
  }
  return <>{children}</>;
}
