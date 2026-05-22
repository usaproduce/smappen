import { useState } from 'react';
import { X } from 'lucide-react';
import toast from 'react-hot-toast';
import { areasApi } from '../../api/areas';
import { useProjectStore } from '../../stores/projectStore';
import { AREA_PALETTE } from '../../utils/colors';
import type { Area } from '../../types';

export default function AreaEditor({ area, onClose }: { area: Area; onClose: () => void }) {
  const { updateArea, removeArea } = useProjectStore();
  const [name, setName] = useState(area.name);
  const [color, setColor] = useState(area.fill_color);
  const [opacity, setOpacity] = useState(area.fill_opacity);
  const [notes, setNotes] = useState(area.notes ?? '');
  const [saving, setSaving] = useState(false);

  async function save() {
    setSaving(true);
    try {
      const a = await areasApi.update(area.id, { name, fill_color: color, stroke_color: color, fill_opacity: opacity, notes });
      updateArea({ ...area, ...a });
      toast.success('Saved');
      onClose();
    } catch (e: any) {
      toast.error('Save failed');
    } finally { setSaving(false); }
  }
  async function destroy() {
    if (!confirm('Delete area?')) return;
    await areasApi.delete(area.id);
    removeArea(area.id);
    toast.success('Deleted');
    onClose();
  }

  return (
    <div className="fixed inset-0 bg-black/30 flex items-center justify-center z-50 p-4" onClick={onClose}>
      <div className="bg-white rounded-lg shadow-xl max-w-md w-full" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between p-4 border-b border-slate-100">
          <h2 className="font-bold">Edit Area</h2>
          <button className="btn btn-ghost p-1" onClick={onClose}><X size={16} /></button>
        </div>
        <div className="p-4 space-y-3">
          <div><label className="label">Name</label><input className="input" value={name} onChange={(e) => setName(e.target.value)} /></div>
          <div>
            <label className="label">Color</label>
            <div className="flex flex-wrap gap-2">
              {AREA_PALETTE.map((c) => (
                <button key={c} className={`w-7 h-7 rounded-full ${color === c ? 'ring-2 ring-slate-700' : ''}`} style={{ background: c }} onClick={() => setColor(c)} />
              ))}
            </div>
          </div>
          <div>
            <label className="label">Opacity: {opacity.toFixed(2)}</label>
            <input type="range" min={0.1} max={1} step={0.05} value={opacity} onChange={(e) => setOpacity(+e.target.value)} className="w-full" />
          </div>
          <div><label className="label">Notes</label><textarea className="textarea" rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} /></div>
        </div>
        <div className="p-4 border-t border-slate-100 flex justify-between">
          <button className="btn btn-danger" onClick={destroy}>Delete</button>
          <button className="btn btn-primary" disabled={saving} onClick={save}>{saving ? 'Saving…' : 'Save'}</button>
        </div>
      </div>
    </div>
  );
}
