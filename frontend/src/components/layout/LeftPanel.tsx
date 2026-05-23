import { useEffect } from 'react';
import { useProjectStore } from '../../stores/projectStore';
import { useMapStore } from '../../stores/mapStore';
import { areasApi } from '../../api/areas';
import AreaList from '../areas/AreaList';
import { Plus, Upload, PenSquare, Folder } from 'lucide-react';

interface Props {
  onCreateArea: () => void;
  onImport: () => void;
}

export default function LeftPanel({ onCreateArea, onImport }: Props) {
  const { currentProject, setAreas, areas } = useProjectStore();
  const { startDrawing, fitBoundsToArea } = useMapStore();

  useEffect(() => {
    if (!currentProject) return;
    let cancelled = false;
    areasApi.listForProject(currentProject.id).then((fc) => {
      if (cancelled) return;
      const items = fc.features.map((f: any) => ({
        id: f.id,
        project_id: currentProject.id,
        ...f.properties,
        geometry: f.geometry,
      }));
      setAreas(items);
      if (items.length > 0 && items[0].geometry) fitBoundsToArea(items[0].geometry);
    });
    return () => { cancelled = true; };
  }, [currentProject?.id]);

  if (!currentProject) {
    return (
      <aside className="absolute top-4 left-4 w-[300px] max-h-[calc(100%-2rem)] bg-white rounded-xl shadow-float border border-slate-200 p-4 z-20">
        <p className="text-sm text-slate-500">Create a project to get started.</p>
      </aside>
    );
  }

  return (
    <aside className="absolute top-4 left-4 w-[300px] max-h-[calc(100%-2rem)] bg-white rounded-xl shadow-float border border-slate-200 flex flex-col overflow-hidden z-20">
      {/* "Create new area" CTA matches Smappen's prominent purple button */}
      <div className="p-3 pb-2">
        <button
          className="btn btn-primary w-full justify-center text-sm py-2.5 h-auto"
          onClick={onCreateArea}
        >
          <Plus size={14} /> Create new area
        </button>
      </div>

      {/* Project label */}
      <div className="px-3 pb-2">
        <div className="flex items-center gap-1.5 text-[11px] text-slate-500 font-semibold uppercase tracking-wider">
          <Folder size={11} /> {currentProject.name}
        </div>
        <div className="text-[11px] text-slate-400 mt-0.5">
          {areas.length} area{areas.length === 1 ? '' : 's'}
        </div>
      </div>

      <div className="flex-1 overflow-y-auto">
        <AreaList />
      </div>

      {/* Secondary actions */}
      <div className="p-2 border-t border-slate-100 grid grid-cols-2 gap-2">
        <button className="btn btn-secondary justify-center text-xs h-9" onClick={onImport}>
          <Upload size={12} /> Import
        </button>
        <button className="btn btn-secondary justify-center text-xs h-9" onClick={() => startDrawing('polygon')}>
          <PenSquare size={12} /> Draw
        </button>
      </div>
    </aside>
  );
}
