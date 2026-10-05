import { Navigate, useParams } from 'react-router-dom';
import { Settings2 } from 'lucide-react';

/** The three tabs, in order: "Truck and costs", "Assumptions", "Data and export". */
const TABS = ['truck', 'assumptions', 'data'];

/**
 * STUB (docs/truck-planner/05_FRONTEND.md 4.9 and 9.2). The Spots and Settings package replaces the
 * body: the truck profile, the model's assumptions and, from the day sheet package, the data tab.
 *
 * What stays: the tab in the URL is checked before anything else (an unknown tab goes to `truck`).
 */
export default function SettingsPage() {
  const { tab } = useParams();
  if (tab === undefined || !TABS.includes(tab)) return <Navigate to="/truck/settings/truck" replace />;

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
        <Settings2 size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> Settings
      </h1>
      <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
        Not built yet.
      </p>
    </div>
  );
}
