import { Link } from 'react-router-dom';
import { useAuthStore } from '../../stores/authStore';

export default function FreeBanner() {
  const { user } = useAuthStore();
  if (user?.plan !== 'free') return null;

  return (
    <div className="h-8 flex items-center justify-center text-[13px] bg-white border-b border-slate-200" style={{ color: '#4A4A5A' }}>
      You are using the <b className="mx-1">free</b> version. 🎉&nbsp;
      <Link to="/pricing" className="font-semibold" style={{ color: '#7848BB' }}>
        See our plans →
      </Link>
    </div>
  );
}
