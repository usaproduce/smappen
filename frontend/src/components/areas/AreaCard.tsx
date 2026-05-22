import { useState } from 'react';
import { Trash2, MoreHorizontal, Car, Bike, Footprints, Circle, Clock } from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import { useProjectStore } from '../../stores/projectStore';
import { areasApi } from '../../api/areas';
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

  const isSelected = area.id === selectedAreaId;
  const ModeIcon = modeIcon[area.travel_mode ?? ''] ?? Circle;
  const isIsochrone = area.area_type === 'isochrone' || area.area_type === 'isodistance';

  async function onDelete() {
    if (!confirm(`Delete "${area.name}"?`)) return;
    try {
      await areasApi.delete(area.id);
      removeArea(area.id);
      toast.success('Deleted');
    } catch {
      toast.error('Delete failed');
    }
  }

  return (
    <div
      className={`area-row ${isSelected ? 'selected' : ''}`}
      onClick={() => {
        selectArea(area.id);
        if (area.geometry) fitBoundsToArea(area.geometry);
      }}
    >
      <span className="area-color-dot" style={{ background: area.fill_color }} />
      {isIsochrone ? (
        <span className="area-meta">
          <ModeIcon size={14} />
          {area.travel_time_minutes ? `${area.travel_time_minutes} min` : ''}
        </span>
      ) : (
        <Circle size={14} style={{ color: area.fill_color }} />
      )}
      <span className="area-name">{area.name}</span>
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
