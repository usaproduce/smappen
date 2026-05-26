import { NavLink, Outlet } from 'react-router-dom';
import { User, Users, Plug, CreditCard, KeyRound, Webhook } from 'lucide-react';
import AppNav from '../layout/AppNav';

const ITEMS = [
  { to: '/settings/profile',      label: 'Profile',      icon: User },
  { to: '/settings/team',         label: 'Team',         icon: Users },
  { to: '/settings/integrations', label: 'Integrations', icon: Plug },
  { to: '/settings/webhooks',     label: 'Webhooks',     icon: Webhook },
  { to: '/settings/api',          label: 'API keys',     icon: KeyRound },
  { to: '/settings/billing',      label: 'Billing',      icon: CreditCard },
];

export default function SettingsLayout() {
  return (
    <div className="min-h-screen bg-slate-50">
      <AppNav />
      <div className="max-w-7xl mx-auto px-6 py-6 flex gap-6">
        <aside className="w-56 shrink-0">
          <h1 className="font-extrabold text-lg mb-3 px-2" style={{ color: '#1A1A2E' }}>Settings</h1>
          <nav className="space-y-0.5">
            {ITEMS.map((it) => {
              const Icon = it.icon;
              return (
                <NavLink
                  key={it.to}
                  to={it.to}
                  className={({ isActive }) =>
                    `flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-semibold transition
                     ${isActive ? 'bg-violet-100 text-violet-800' : 'text-slate-600 hover:bg-white'}`
                  }
                >
                  <Icon size={14} /> {it.label}
                </NavLink>
              );
            })}
          </nav>
        </aside>
        <main className="flex-1 min-w-0">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
