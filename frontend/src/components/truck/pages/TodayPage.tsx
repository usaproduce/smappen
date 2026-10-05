import { Link } from 'react-router-dom';
import { CalendarDays, MapPinned, Settings2, Store, type LucideIcon } from 'lucide-react';
import { fmtCount } from '../../../utils/truck/format';
import { useTruck } from '../data';

/**
 * STARTER PAGE (docs/truck-planner/05_FRONTEND.md 4.1 and 9.2). The Week, dates and Today package
 * replaces the body with the real Today: the next thing to do, today's plan, what is left to log,
 * the weather and the fuel price. Until then it is the heading and three ways in. No map here: the
 * landing page is about operations.
 */
export default function TodayPage() {
  const { counts } = useTruck();
  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
        <CalendarDays size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> Today
      </h1>
      <div className="grid gap-3 sm:gap-4 md:grid-cols-3">
        <LinkCard to="/truck/map" icon={MapPinned} title="Open the map" text="See where people are, hour by hour." />
        <LinkCard to="/truck/spots" icon={Store} title="Spots" text={fmtCount(counts.spots) + ' saved'} />
        <LinkCard to="/truck/settings/truck" icon={Settings2} title="Truck and costs" text="Ticket, crew, fuel and fees." />
      </div>
    </div>
  );
}

function LinkCard({ to, icon: Icon, title, text }: { to: string; icon: LucideIcon; title: string; text: string }) {
  return (
    <Link
      to={to}
      className="flex items-start gap-3 bg-white rounded-xl border p-4 sm:p-5 min-h-[72px] hover:bg-slate-50"
      style={{ borderColor: 'var(--line-soft)' }}
    >
      <span
        className="inline-flex items-center justify-center w-10 h-10 rounded-lg flex-shrink-0"
        style={{ background: 'var(--brand-light)', color: 'var(--brand)' }}
        aria-hidden="true"
      >
        <Icon size={20} />
      </span>
      <span className="min-w-0">
        <span className="block text-base font-extrabold" style={{ color: 'var(--ink)' }}>
          {title}
        </span>
        <span className="block text-sm font-semibold mt-0.5" style={{ color: 'var(--body)' }}>
          {text}
        </span>
      </span>
    </Link>
  );
}
