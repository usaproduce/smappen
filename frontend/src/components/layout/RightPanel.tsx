import { useEffect, useState } from 'react';
import { X, MapPin, Car, Bike, Footprints, Clock, Info, Users, Building2, Database, Columns2, Camera } from 'lucide-react';
import StreetViewModal from '../map/StreetViewModal';
import { useMapStore } from '../../stores/mapStore';
import { useProjectStore } from '../../stores/projectStore';
import { useQuery } from '@tanstack/react-query';
import { areasApi } from '../../api/areas';
import DemographicsPanel from '../analytics/DemographicsPanel';
import POISearchPanel from '../analytics/POISearchPanel';
import ComparisonView from '../analytics/ComparisonView';
import AnimatedNumber from '../common/AnimatedNumber';
import ReportButton from '../data/ReportButton';
import ExportDialog from '../data/ExportDialog';

const TABS: { key: Tab; label: string; icon: any }[] = [
  { key: 'overview',     label: 'Overview',  icon: Info },
  { key: 'demographics', label: 'People',    icon: Users },
  { key: 'businesses',   label: 'Businesses', icon: Building2 },
  { key: 'data',         label: 'Data',      icon: Database },
];

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
  const [comparePickOpen, setComparePickOpen] = useState(false);
  const [streetViewOpen, setStreetViewOpen] = useState(false);
  const [compareWith, setCompareWith] = useState<string | null>(null);

  const area = areas.find((a) => a.id === selectedAreaId);
  const { currentProject, folders } = useProjectStore() as any;
  const folder = area?.folder_id ? (folders ?? []).find((f: any) => f.id === area.folder_id) : null;

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
        {/* VT13 — breadcrumb so context is always visible. Truncates the
            project + folder names individually so a long project name
            doesn't squeeze out the area name beneath. */}
        {(currentProject?.name || folder?.name) && (
          <div className="text-[10px] uppercase font-bold tracking-wider text-slate-400 flex items-center gap-1 mb-1 min-w-0">
            {currentProject?.name && (
              <span className="truncate max-w-[80px]" title={currentProject.name}>{currentProject.name}</span>
            )}
            {currentProject?.name && folder?.name && <span>›</span>}
            {folder?.name && (
              <span className="truncate max-w-[100px]" title={folder.name} style={{ color: folder.color || undefined }}>
                {folder.name}
              </span>
            )}
          </div>
        )}
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
          <div className="flex items-center gap-0.5">
            {/* BF2 — open a small picker, then ComparisonView modal. Previous
                version navigated to a hash route that didn't exist. */}
            <button
              className="text-slate-500 hover:text-violet-700 p-1 rounded hover:bg-slate-50 transition-colors"
              onClick={() => setComparePickOpen((v) => !v)}
              title="Compare this area with another"
            >
              <Columns2 size={16} />
            </button>
            <button className="text-slate-400 hover:text-slate-700 p-1 rounded hover:bg-slate-50" onClick={() => selectArea(null)} title="Close">
              <X size={16} />
            </button>
          </div>
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

      {/* Tweak #10 — icon-with-label segmented control, replacing text-only tabs.
          Compact icons sit above the label so the tab strip reads at a glance.
          A floating violet pill slides under the active tab. */}
      <div className="px-2 pt-2 pb-1 border-b border-slate-100">
        <div className="relative flex bg-slate-100 rounded-lg p-1 gap-0.5">
          {TABS.map((t) => {
            const Active = tab === t.key;
            const Icon = t.icon;
            return (
              <button
                key={t.key}
                onClick={() => setTab(t.key)}
                className={`relative flex-1 flex flex-col items-center justify-center gap-0.5 py-1.5 rounded-md text-[10px] font-bold transition-colors ${
                  Active
                    ? 'bg-white text-violet-700 shadow-sm'
                    : 'text-slate-500 hover:text-slate-800'
                }`}
                title={t.label}
              >
                <Icon size={15} />
                <span className="tracking-wide">{t.label}</span>
              </button>
            );
          })}
        </div>
      </div>

      {/* Body */}
      <div className="flex-1 overflow-y-auto">
        {tab === 'overview' && (
          <div className="p-4 space-y-3">
            {/* Tweak #11 — four colored stat tiles instead of two plain cards.
                Each tile renders gracefully when data is missing so radius/
                manual areas without demographics don't show a sea of dashes. */}
            <OverviewStatTiles area={area} />

            <div className="flex gap-2">
              <ReportButton areaId={area.id} />
              <button className="btn btn-secondary flex-1 justify-center" onClick={() => setExportOpen(true)}>
                Export
              </button>
            </div>

            {/* Street View — drops the user into a panorama at the area's
                center. Only when we have a center point (every area type
                except free-draw + import). */}
            {area.center_lat != null && area.center_lng != null && (
              <button
                onClick={() => setStreetViewOpen(true)}
                className="w-full rounded-lg p-3 border border-slate-200 hover:border-violet-400 hover:bg-violet-50 transition flex items-center gap-3 text-left group"
              >
                <div className="w-9 h-9 rounded-full bg-violet-100 group-hover:bg-violet-200 flex items-center justify-center transition">
                  <Camera size={18} style={{ color: '#7848BB' }} />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="font-bold text-sm" style={{ color: '#1A1A2E' }}>Open Street View</div>
                  <div className="text-[11px] text-slate-500">See what this location looks like on the ground</div>
                </div>
              </button>
            )}

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
      {streetViewOpen && area.center_lat != null && area.center_lng != null && (
        <StreetViewModal
          lat={area.center_lat}
          lng={area.center_lng}
          label={area.name}
          onClose={() => setStreetViewOpen(false)}
        />
      )}

      {/* BF2 — Compare with… picker. Lists sibling areas in the same
          project; selecting one opens ComparisonView with both areas. */}
      {comparePickOpen && (
        <div
          className="absolute top-14 right-2 z-30 bg-white rounded-lg shadow-lg border border-slate-200 w-[260px] max-h-72 overflow-auto card-expand"
          onClick={(e) => e.stopPropagation()}
        >
          <div className="px-3 py-2 text-[11px] uppercase tracking-wider font-bold text-slate-500 border-b border-slate-100">
            Compare with…
          </div>
          {areas.filter((a) => a.id !== area.id).length === 0 ? (
            <div className="px-3 py-4 text-xs text-slate-500 text-center">No other areas yet.</div>
          ) : (
            areas
              .filter((a) => a.id !== area.id)
              .slice(0, 30)
              .map((a) => (
                <button
                  key={a.id}
                  onClick={() => { setCompareWith(a.id); setComparePickOpen(false); }}
                  className="w-full text-left px-3 py-2 hover:bg-violet-50 text-sm flex items-center gap-2"
                >
                  <span
                    className="inline-block w-3 h-3 rounded-full border border-black/10 shrink-0"
                    style={{ background: a.fill_color || '#7848BB' }}
                  />
                  <span className="truncate">{a.name}</span>
                </button>
              ))
          )}
        </div>
      )}
      {compareWith && (
        <ComparisonView areaIds={[area.id, compareWith]} onClose={() => setCompareWith(null)} />
      )}
    </aside>
  );
}

function OverviewStatTiles({ area }: { area: any }) {
  // Pull from the demographics_cache first; if it's empty (radius-mode areas
  // and freshly-drawn polygons start with no cache), kick off a lazy fetch
  // via React Query so the tiles populate without the user having to
  // visit the Demographics tab manually. Same endpoint the tab uses, so
  // results get cached and a tab visit afterwards is instant.
  const dc: any = area.demographics_cache ?? {};
  const hasCachedPop = (typeof dc.population?.total === 'number' && dc.population.total > 0)
    || (typeof dc.population === 'number' && dc.population > 0);

  const { data: live, isLoading } = useQuery({
    queryKey: ['demographics', area.id],
    queryFn: () => areasApi.demographics(area.id),
    enabled: !hasCachedPop, // only fetch when the cache is empty
    staleTime: 5 * 60 * 1000,
  });
  const merged: any = hasCachedPop ? dc : (live ?? {});

  // Loading state — large isochrones can take 5-15s to compute demographics
  // server-side (many tracts to intersect). Replace the dashed tiles with
  // shimmer skeletons + a "computing" caption so the user knows something
  // is happening instead of staring at empty placeholders.
  if (isLoading && !hasCachedPop) {
    return (
      <div className="grid grid-cols-2 gap-2">
        {[0, 1, 2, 3].map((i) => (
          <div key={i} className="rounded-lg p-3 bg-slate-50 border border-slate-200">
            <div className="skeleton skeleton-line w-1/2" style={{ height: 9 }} />
            <div className="skeleton mt-1.5" style={{ height: 22, width: '70%' }} />
          </div>
        ))}
        <div className="col-span-2 text-[10px] text-slate-500 text-center mt-1 flex items-center justify-center gap-1.5">
          <span className="spinner" style={{ width: 10, height: 10, borderWidth: 1.5 }} />
          Computing demographics — large areas can take 10-15s on a cold cache.
        </div>
      </div>
    );
  }

  const pop: number | null =
    typeof merged.population?.total === 'number' ? merged.population.total
    : typeof merged.population === 'number' ? merged.population
    : null;
  const income: number | null =
    merged.income?.median_household_income ?? merged.income?.median_household ?? merged.median_household_income ?? null;
  const households: number | null = merged.housing?.total_units ?? merged.housing_units_total ?? null;

  // Quick & dirty area-size estimate from the geometry bounds. Not exact
  // (no equal-area projection) but enough to show a ballpark.
  const sqKm: number | null = (() => {
    if (!area.geometry) return null;
    try {
      // Defer to a haversine bbox calc — good to ~5% for sub-50km tiles.
      const ll = (a: number) => (a * Math.PI) / 180;
      const bounds = bboxOf(area.geometry);
      if (!bounds) return null;
      const { minLat, minLng, maxLat, maxLng } = bounds;
      const widthKm = haversineKm(minLat, minLng, minLat, maxLng);
      const heightKm = haversineKm(minLat, minLng, maxLat, minLng);
      // Use travel_time radius if we have it (better than bbox for circles).
      if (area.area_type === 'radius' && area.travel_distance_km) {
        return Math.PI * area.travel_distance_km * area.travel_distance_km;
      }
      // Rough — multiply by 0.7 to approximate polygon area vs bbox.
      void ll;
      return widthKm * heightKm * 0.7;
    } catch {
      return null;
    }
  })();

  // VT12 — animated tile values. Each tile's number eases in over 350ms;
  // strings (formatted with $, km², compact suffix) wrap an AnimatedNumber.
  const tiles: { label: string; value: number | null; format: (n: number) => string; bg: string; fg: string }[] = [
    { label: 'Population',    value: pop,        format: (n) => formatCompact(n),                  bg: '#EDE5F7', fg: '#5C2D91' },
    { label: 'Median income', value: income,     format: (n) => '$' + formatCompact(n),            bg: '#D1FAE5', fg: '#065F46' },
    { label: 'Households',    value: households, format: (n) => formatCompact(n),                  bg: '#DBEAFE', fg: '#1D4ED8' },
    { label: 'Area',          value: sqKm,       format: (n) => formatCompact(n) + ' km²',         bg: '#FEF3C7', fg: '#92400E' },
  ];
  return (
    <div className="grid grid-cols-2 gap-2">
      {tiles.map((t) => (
        <div key={t.label} className="rounded-lg p-3" style={{ background: t.bg }}>
          <div className="text-[10px] uppercase font-bold tracking-wider" style={{ color: t.fg, opacity: 0.7 }}>{t.label}</div>
          <div className="text-lg font-extrabold mt-0.5" style={{ color: t.fg }}>
            <AnimatedNumber value={t.value ?? null} format={t.format} />
          </div>
        </div>
      ))}
    </div>
  );
}

function formatCompact(n: number): string {
  if (Math.abs(n) >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M';
  if (Math.abs(n) >= 1_000)     return (n / 1_000).toFixed(1).replace(/\.0$/, '') + 'K';
  return Math.round(n).toLocaleString();
}

function haversineKm(la1: number, lo1: number, la2: number, lo2: number) {
  const R = 6371;
  const dLat = ((la2 - la1) * Math.PI) / 180;
  const dLon = ((lo2 - lo1) * Math.PI) / 180;
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos((la1 * Math.PI) / 180) * Math.cos((la2 * Math.PI) / 180) * Math.sin(dLon / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(a));
}

function bboxOf(geom: any): { minLat: number; minLng: number; maxLat: number; maxLng: number } | null {
  let minLat = Infinity, minLng = Infinity, maxLat = -Infinity, maxLng = -Infinity;
  const visit = (rings: any[]) => {
    for (const ring of rings) for (const [lng, lat] of ring) {
      if (lat < minLat) minLat = lat;
      if (lat > maxLat) maxLat = lat;
      if (lng < minLng) minLng = lng;
      if (lng > maxLng) maxLng = lng;
    }
  };
  if (geom.type === 'Polygon') visit(geom.coordinates);
  else if (geom.type === 'MultiPolygon') for (const poly of geom.coordinates) visit(poly);
  else return null;
  if (minLat === Infinity) return null;
  return { minLat, minLng, maxLat, maxLng };
}
