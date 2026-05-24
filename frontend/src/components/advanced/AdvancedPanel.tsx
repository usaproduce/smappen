import { lazy, Suspense, useState } from 'react';
import { X, Sparkles, Layers, Users, Compass, Target, Tag, MessageCircle, History, Bell, MapPinned } from 'lucide-react';
import { useProjectStore } from '../../stores/projectStore';
import { Spinner } from './shared';
import ErrorBoundary from '../ErrorBoundary';

// Per-tab lazy chunks. Each tab gets its own JS file under /app/assets,
// so opening the Versions tab doesn't pay the cost of loading the
// territory generator pipeline. Vite handles the codesplit automatically.
const TerritoriesTab    = lazy(() => import('./TerritoriesTab'));
const CannibalizeTab    = lazy(() => import('./CannibalizeTab'));
const TrafficTab        = lazy(() => import('./TrafficTab'));
const OptimizeTab       = lazy(() => import('./OptimizeTab'));
const SegmentsTab       = lazy(() => import('./SegmentsTab'));
const CommentsTab       = lazy(() => import('./CommentsTab'));
const VersionsTab       = lazy(() => import('./VersionsTab'));
const CompetitorsTab    = lazy(() => import('./CompetitorsTab'));
const FieldTab          = lazy(() => import('./FieldTab'));

type TabKey =
  | 'territories' | 'cannibalization' | 'traffic' | 'optimize'
  | 'segments' | 'comments' | 'versions' | 'competitors' | 'field';

const TABS: { key: TabKey; label: string; icon: any }[] = [
  { key: 'territories',     label: 'Territories',     icon: Layers },
  { key: 'cannibalization', label: 'Cannibalize',     icon: Users },
  { key: 'traffic',         label: 'Traffic',         icon: Compass },
  { key: 'optimize',        label: 'Optimize',        icon: Target },
  { key: 'segments',        label: 'Segments',        icon: Tag },
  { key: 'comments',        label: 'Comments',        icon: MessageCircle },
  { key: 'versions',        label: 'Versions',        icon: History },
  { key: 'competitors',     label: 'Competitors',     icon: Bell },
  { key: 'field',           label: 'Field notes',     icon: MapPinned },
];

interface Props { onClose: () => void; }

export default function AdvancedPanel({ onClose }: Props) {
  const [tab, setTab] = useState<TabKey>('territories');
  const { currentProject } = useProjectStore();

  return (
    <aside className="absolute top-4 right-[68px] w-[400px] max-h-[calc(100%-2rem)] bg-white rounded-xl shadow-float border border-slate-200 z-30 flex flex-col panel-slide-right">
      <header className="flex items-center justify-between px-3 py-2.5 border-b border-slate-200">
        <div className="flex items-center gap-2 font-bold text-sm" style={{ color: '#1A1A2E' }}>
          <Sparkles size={15} style={{ color: '#7848BB' }} /> Advanced
        </div>
        <button onClick={onClose} className="text-slate-400 hover:text-slate-700 p-1 rounded hover:bg-slate-50">
          <X size={14} />
        </button>
      </header>

      <nav className="px-2 pt-2 flex flex-wrap gap-1 border-b border-slate-200 pb-2 sticky top-0 bg-white z-10">
        {TABS.map((t) => {
          const Icon = t.icon;
          return (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className={`text-[11px] font-semibold px-2.5 py-1.5 rounded-md inline-flex items-center gap-1.5 transition
                ${tab === t.key ? 'bg-violet-100 text-violet-800' : 'text-slate-600 hover:bg-slate-50'}`}
            >
              <Icon size={13} /> {t.label}
            </button>
          );
        })}
      </nav>

      <div className="px-3 py-3 overflow-y-auto" style={{ maxHeight: 'calc(100vh - 160px)' }}>
        {!currentProject && (
          <div className="p-6 text-sm text-slate-500 text-center">Open a project to use advanced features.</div>
        )}
        {currentProject && (
          <ErrorBoundary scope={`The ${tab} tab`} inline>
            <Suspense fallback={<div className="flex items-center gap-2 text-sm text-slate-500"><Spinner /> Loading…</div>}>
              {tab === 'territories'     && <TerritoriesTab projectId={currentProject.id} />}
              {tab === 'cannibalization' && <CannibalizeTab projectId={currentProject.id} />}
              {tab === 'traffic'         && <TrafficTab />}
              {tab === 'optimize'        && <OptimizeTab projectId={currentProject.id} />}
              {tab === 'segments'        && <SegmentsTab projectId={currentProject.id} />}
              {tab === 'comments'        && <CommentsTab projectId={currentProject.id} />}
              {tab === 'versions'        && <VersionsTab projectId={currentProject.id} />}
              {tab === 'competitors'     && <CompetitorsTab projectId={currentProject.id} />}
              {tab === 'field'           && <FieldTab projectId={currentProject.id} />}
            </Suspense>
          </ErrorBoundary>
        )}
      </div>
    </aside>
  );
}
