import { useEffect, useState } from 'react';
import { X, MapPin, Car, Bike, Footprints, Clock } from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import { useProjectStore } from '../../stores/projectStore';
import DemographicsPanel from '../analytics/DemographicsPanel';
import POISearchPanel from '../analytics/POISearchPanel';
import ReportButton from '../data/ReportButton';
import ExportDialog from '../data/ExportDialog';

type Tab = 'overview' | 'demographics' | 'businesses' | 'data';

const modeIcon: Record<string, any> = {
  'driving-car': Car,
  'cycling-regular': Bike,
  'foot-walking': Footprints,
};

const modeLabel: Record<string, string> = {
  'driving-car': 'Car',
  'cycling-regular': 'Bike',
  'foot-walking': 'Walk',
};

export default function RightPanel() {
  const { selectedAreaId, selectArea, openTimeMachine, rightPanelTab, setRightPanelTab } = useMapStore();
  const { areas } = useProjectStore();
  // Tab state lives in mapStore so the right-toolbar Demographics/Businesses
  // buttons can deep-link into a specific tab when an area is selected.
  const tab = rightPanelTab as Tab;
  const setTab = setRightPanelTab as (t: Tab) => void;
  const [exportOpen, setExportOpen] = useState(false);

  const area = areas.find((a) => a.id === selectedAreaId);

  // Esc closes the panel.
  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape' && selectedAreaId) selectArea(null);
    }
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [selectedAreaId, selectArea]);

  if (!area) return null;
  // Fall back to MapPin (a neutral generic pin) for radius/manual areas that
  // have no travel_mode, instead of the old Hexagon which looked unrelated.
  const ModeIcon = modeIcon[area.travel_mode ?? ''] ?? MapPin;

  return (
    <aside className="absolute top-4 right-20 w-[300px] md:w-[340px] lg:w-[360px] max-h-[calc(100%-2rem)] bg-white rounded-xl shadow-float border border-slate-200 flex flex-col overflow-hidden z-20 panel-slide-right">
      {/* Header */}
      <div className="px-4 pt-3 pb-3 border-b border-slate-100">
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <div className="font-bold text-[15px] leading-tight truncate" style={{ color: '#1A1A2E' }}>
              {area.name}
            </div>
            {area.center_address && (
              <div className="text-xs text-slate-500 truncate mt-0.5 flex items-center gap-1">
                <MapPin size={11} /> {area.center_address}
              </div>
            )}
          </div>
          <button className="text-slate-400 hover:text-slate-700 p-1 rounded hover:bg-slate-50" onClick={() => selectArea(null)} title="Close">
            <X size={16} />
          </button>
        </div>

        {/* Type chip row */}
        <div className="flex flex-wrap gap-1.5 mt-2">
          <span
            className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold"
            style={{ background: '#EDE5F7', color: '#5C2D91' }}
          >
            <ModeIcon size={11} />
            {area.travel_time_minutes ? `${area.travel_time_minutes} min` : area.area_type}
            {area.travel_mode ? ` · ${modeLabel[area.travel_mode] ?? area.travel_mode}` : ''}
          </span>
          {(area as any).demographics_cache?.population?.total ? (
            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700">
              {(area as any).demographics_cache.population.total.toLocaleString()} people
            </span>
          ) : null}
        </div>
      </div>

      {/* Tabs */}
      <div className="flex border-b border-slate-100 px-2">
        {(['overview', 'demographics', 'businesses', 'data'] as Tab[]).map((t) => (
          <button
            key={t}
            onClick={() => setTab(t)}
            className={`relative px-3 py-2.5 text-xs font-semibold capitalize transition-colors ${
              tab === t
                ? 'text-violet-700'
                : 'text-slate-500 hover:text-slate-700'
            }`}
          >
            {t}
            {tab === t && (
              <span className="absolute bottom-0 left-2 right-2 h-0.5 rounded-full" style={{ background: '#7848BB' }} />
            )}
          </button>
        ))}
      </div>

      {/* Body */}
      <div className="flex-1 overflow-y-auto">
        {tab === 'overview' && (
          <div className="p-4 space-y-3">
            <div className="grid grid-cols-2 gap-2">
              <div className="rounded-lg p-3 border border-slate-200">
                <div className="text-[10px] text-slate-500 uppercase font-bold tracking-wider">Type</div>
                <div className="text-sm font-semibold mt-0.5" style={{ color: '#1A1A2E' }}>
                  {area.area_type === 'isochrone' ? 'Travel time' : area.area_type === 'radius' ? 'Radius' : 'Manual'}
                </div>
              </div>
              <div className="rounded-lg p-3 border border-slate-200">
                <div className="text-[10px] text-slate-500 uppercase font-bold tracking-wider">Created</div>
                <div className="text-sm font-semibold mt-0.5" style={{ color: '#1A1A2E' }}>
                  {(area as any).created_at ? new Date((area as any).created_at).toLocaleDateString() : '—'}
                </div>
              </div>
            </div>

            <div className="flex gap-2">
              <ReportButton areaId={area.id} />
              <button className="btn btn-secondary flex-1 justify-center" onClick={() => setExportOpen(true)}>
                Export
              </button>
            </div>

            {/* Time machine — only available for travel-time areas, since
                the animation only makes sense if the polygon was originally
                computed from a drive-time budget. */}
            {area.area_type === 'isochrone' && area.center_lat != null && area.center_lng != null && (
              <button
                onClick={() => openTimeMachine({
                  lat: area.center_lat!,
                  lng: area.center_lng!,
                  minutes: area.travel_time_minutes ?? 15,
                  color: area.fill_color ?? '#7848BB',
                })}
                className="w-full rounded-lg p-3 border-2 border-dashed border-violet-300 hover:border-violet-500 hover:bg-violet-50 transition flex items-center gap-3 text-left group"
              >
                <div className="w-9 h-9 rounded-full bg-violet-100 group-hover:bg-violet-200 flex items-center justify-center transition">
                  <Clock size={18} style={{ color: '#7848BB' }} />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="font-bold text-sm" style={{ color: '#1A1A2E' }}>Drive-time over a full day</div>
                  <div className="text-[11px] text-slate-500">Watch this area shrink & grow with traffic</div>
                </div>
              </button>
            )}

            {area.notes && (
              <div className="rounded-lg p-3 bg-slate-50 border border-slate-200">
                <div className="text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Notes</div>
                <div className="text-sm text-slate-700 whitespace-pre-wrap">{area.notes}</div>
              </div>
            )}

            <div className="text-xs text-slate-500 px-1 mt-2">
              Switch to <button className="text-violet-700 font-semibold" onClick={() => setTab('demographics')}>Demographics</button> to
              see population, income, age, and housing for this area.
            </div>
          </div>
        )}
        {tab === 'demographics' && <DemographicsPanel areaId={area.id} />}
        {tab === 'businesses' && <POISearchPanel area={area} />}
        {tab === 'data' && (
          <div className="p-4 text-sm text-slate-500">
            Import a CSV via the toolbar to see your points inside this area.
          </div>
        )}
      </div>
      {exportOpen && <ExportDialog onClose={() => setExportOpen(false)} areaId={area.id} />}
    </aside>
  );
}
