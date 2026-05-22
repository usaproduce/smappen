import { useState } from 'react';
import { X } from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import { useProjectStore } from '../../stores/projectStore';
import DemographicsPanel from '../analytics/DemographicsPanel';
import POISearchPanel from '../analytics/POISearchPanel';
import ReportButton from '../data/ReportButton';
import ExportDialog from '../data/ExportDialog';

type Tab = 'overview' | 'demographics' | 'businesses' | 'data';

export default function RightPanel() {
  const { selectedAreaId, selectArea } = useMapStore();
  const { areas } = useProjectStore();
  const [tab, setTab] = useState<Tab>('overview');
  const [exportOpen, setExportOpen] = useState(false);

  const area = areas.find((a) => a.id === selectedAreaId);
  if (!area) return null;

  return (
    <aside className="w-[360px] bg-white border-l border-slate-200 flex flex-col">
      <div className="p-3 border-b border-slate-100 flex items-center justify-between">
        <div className="min-w-0">
          <div className="font-semibold truncate" style={{ color: '#1e3a5f' }}>{area.name}</div>
          <div className="text-xs text-slate-500 truncate">{area.center_address}</div>
        </div>
        <button className="btn btn-ghost p-1" onClick={() => selectArea(null)}><X size={16} /></button>
      </div>
      <div className="flex border-b border-slate-100 px-3">
        {(['overview', 'demographics', 'businesses', 'data'] as Tab[]).map((t) => (
          <button key={t} onClick={() => setTab(t)}
            className={`px-3 py-2 text-xs font-semibold capitalize ${tab === t ? 'border-b-2 border-violet-600 text-violet-700' : 'text-slate-500'}`}>
            {t}
          </button>
        ))}
      </div>
      <div className="flex-1 overflow-y-auto">
        {tab === 'overview' && (
          <div className="p-4 space-y-3">
            <div className="card">
              <div className="text-xs text-slate-500 uppercase font-semibold">Type</div>
              <div className="font-medium">{area.area_type}{area.travel_time_minutes ? ` · ${area.travel_time_minutes} min ${area.travel_mode}` : ''}</div>
            </div>
            <div className="flex gap-2">
              <ReportButton areaId={area.id} />
              <button className="btn btn-secondary" onClick={() => setExportOpen(true)}>Export</button>
            </div>
            {area.notes && <div className="text-sm text-slate-600">{area.notes}</div>}
          </div>
        )}
        {tab === 'demographics' && <DemographicsPanel areaId={area.id} />}
        {tab === 'businesses' && <POISearchPanel area={area} />}
        {tab === 'data' && (
          <div className="p-4 text-sm text-slate-500">Use the Data tab to inspect imported points inside this area.</div>
        )}
      </div>
      {exportOpen && <ExportDialog onClose={() => setExportOpen(false)} areaId={area.id} />}
    </aside>
  );
}
