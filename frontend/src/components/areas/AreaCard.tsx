import { useState } from 'react';
import { Trash2, MoreHorizontal, Car, Bike, Footprints, Hexagon } from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import { useProjectStore } from '../../stores/projectStore';
import { areasApi } from '../../api/areas';
import { formatNumber } from '../../utils/format';
import type { Area } from '../../types';
import toast from 'react-hot-toast';

const modeIcon: Record<string, any> = {
  'driving-car': Car,
  'cycling-regular': Bike,
  'foot-walking': Footprints,
};

export default function AreaCard({ area }: { area: Area }) {
  const { selectedAreaId, selectArea, fitBoundsToArea } = useMapStore();
  const { removeArea } = useProjectStore();
  const [menuOpen, setMenuOpen] = useState(false);

  const Icon = modeIcon[area.travel_mode ?? ''] ?? Hexagon;
  const isSelected = area.id === selectedAreaId;

  const pop = (area as any).demographics_cache?.population?.total;

  async function onDelete() {
    if (!confirm(`Delete "${area.name}"?`)) return;
    try {
      await areasApi.delete(area.id);
      removeArea(area.id);
      toast.success('Deleted');
    } catch (e: any) {
      toast.error('Delete failed');
    }
  }

  return (
    <div
      className={`flex items-center gap-2 p-2 rounded cursor-pointer border ${isSelected ? 'border-violet-300 bg-violet-50' : 'border-transparent hover:bg-slate-50'}`}
      onClick={() => {
        selectArea(area.id);
        if (area.geometry) fitBoundsToArea(area.geometry);
      }}
    >
      <span className="w-3 h-3 rounded-full" style={{ background: area.fill_color }} />
      <div className="flex-1 min-w-0">
        <div className="text-sm font-medium truncate" style={{ color: 'var(--ink)' }}>{area.name}</div>
        <div className="text-xs text-slate-500 flex items-center gap-1">
          <Icon size={10} />
          {area.travel_time_minutes ? `${area.travel_time_minutes} min` : area.area_type}
          {pop ? <span className="ml-1">· {formatNumber(pop)} pop</span> : null}
        </div>
      </div>
      <div className="relative" onClick={(e) => e.stopPropagation()}>
        <button className="text-slate-400 hover:text-slate-700 p-1" onClick={() => setMenuOpen(!menuOpen)}>
          <MoreHorizontal size={14} />
        </button>
        {menuOpen && (
          <div className="absolute right-0 top-full mt-1 bg-white border border-slate-200 rounded shadow-md z-20 min-w-[120px]">
            <button className="block w-full text-left px-3 py-2 text-sm hover:bg-slate-50 text-red-600" onClick={onDelete}>
              <Trash2 size={12} className="inline mr-1" /> Delete
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
