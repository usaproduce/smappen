import { useEffect, useState } from 'react';
import { useProjectStore } from '../../stores/projectStore';
import { useMapStore } from '../../stores/mapStore';
import { areasApi } from '../../api/areas';
import AreaList from '../areas/AreaList';
import AreaCreator from '../areas/AreaCreator';
import ImportWizard from '../data/ImportWizard';
import { Plus, Upload, PenSquare, MapPin } from 'lucide-react';

export default function LeftPanel() {
  const { currentProject, setAreas, areas } = useProjectStore();
  const { startDrawing, fitBoundsToArea } = useMapStore();
  const [creatorOpen, setCreatorOpen] = useState(false);
  const [importOpen, setImportOpen] = useState(false);

  useEffect(() => {
    if (!currentProject) return;
    areasApi.listForProject(currentProject.id).then((fc) => {
      const items = fc.features.map((f: any) => ({
        id: f.id,
        project_id: currentProject.id,
        ...f.properties,
        geometry: f.geometry,
      }));
      setAreas(items);
      if (items.length > 0 && items[0].geometry) fitBoundsToArea(items[0].geometry);
    });
  }, [currentProject?.id]);

  if (!currentProject) {
    return (
      <aside className="w-[300px] bg-white border-r border-slate-200 p-4">
        <p className="text-sm text-slate-500">Create a project to get started.</p>
      </aside>
    );
  }

  return (
    <aside className="w-[300px] bg-white border-r border-slate-200 flex flex-col">
      <div className="p-3 border-b border-slate-100">
        <div className="font-semibold text-sm" style={{ color: '#1e3a5f' }}>{currentProject.name}</div>
        <div className="text-xs text-slate-500">{areas.length} area{areas.length === 1 ? '' : 's'}</div>
      </div>
      <div className="flex-1 overflow-y-auto">
        <AreaList />
      </div>
      <div className="p-3 border-t border-slate-100 grid grid-cols-3 gap-2">
        <button className="btn btn-primary justify-center" onClick={() => setCreatorOpen(true)}>
          <Plus size={14} /> Area
        </button>
        <button className="btn btn-secondary justify-center" onClick={() => setImportOpen(true)}>
          <Upload size={14} /> Import
        </button>
        <button className="btn btn-secondary justify-center" onClick={() => startDrawing('polygon')}>
          <PenSquare size={14} /> Draw
        </button>
      </div>
      {creatorOpen && <AreaCreator onClose={() => setCreatorOpen(false)} />}
      {importOpen && <ImportWizard onClose={() => setImportOpen(false)} />}
    </aside>
  );
}
