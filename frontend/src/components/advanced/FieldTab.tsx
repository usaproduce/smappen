import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { useMapStore } from '../../stores/mapStore';
import { fieldNoteApi, type FieldNote } from '../../api/advanced';
import { Empty } from './shared';

export default function FieldTab({ projectId }: { projectId: string }) {
  const [notes, setNotes] = useState<FieldNote[]>([]);
  const [body, setBody] = useState('');
  const [pos, setPos] = useState<{ lat: number; lng: number; accuracy?: number } | null>(null);
  const [busy, setBusy] = useState(false);
  const { mapInstance } = useMapStore();

  async function load() {
    try { setNotes((await fieldNoteApi.list(projectId)).field_notes); } catch {}
  }
  useEffect(() => { load(); }, [projectId]);

  function locate() {
    if (!navigator.geolocation) {
      toast.error('Geolocation not available');
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (p) => setPos({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }),
      () => toast.error('Could not get location'),
      { enableHighAccuracy: true, timeout: 10_000 }
    );
  }

  function useMapCenter() {
    const c = mapInstance?.getCenter();
    if (!c) return;
    setPos({ lat: c.lat(), lng: c.lng() });
  }

  async function save() {
    if (!body.trim() || !pos) return;
    setBusy(true);
    try {
      await fieldNoteApi.create(projectId, {
        body: body.trim(), lat: pos.lat, lng: pos.lng,
        accuracy_m: pos.accuracy,
      });
      setBody('');
      await load();
      toast.success('Note saved');
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-600">Capture geo-stamped notes from the field. Works offline (PWA-installed).</p>
      <textarea className="input text-sm" placeholder="What did you see?" value={body}
        onChange={(e) => setBody(e.target.value)} rows={2} />
      <div className="flex gap-2">
        <button className="btn btn-secondary h-9 flex-1 text-xs" onClick={locate}>Use my location</button>
        <button className="btn btn-secondary h-9 flex-1 text-xs" onClick={useMapCenter}>Use map center</button>
      </div>
      {pos && (
        <div className="text-[11px] text-slate-600 bg-slate-50 rounded px-2 py-1">
          {pos.lat.toFixed(5)}, {pos.lng.toFixed(5)}
          {pos.accuracy ? ` · ±${Math.round(pos.accuracy)}m` : ''}
        </div>
      )}
      <button className="btn btn-primary w-full h-9" onClick={save} disabled={busy || !pos || !body.trim()}>
        Save note
      </button>
      <ul className="space-y-2 mt-2">
        {notes.map((n) => (
          <li key={n.id} className="bg-white border border-slate-200 rounded p-2 text-xs">
            <div className="flex items-center justify-between mb-0.5">
              <span className="font-semibold" style={{ color: '#1A1A2E' }}>{n.author_name ?? 'You'}</span>
              <span className="text-slate-400">{new Date(n.captured_at).toLocaleString()}</span>
            </div>
            <div className="text-slate-700">{n.body}</div>
            <div className="text-slate-500 mt-0.5">{n.lat.toFixed(5)}, {n.lng.toFixed(5)}</div>
          </li>
        ))}
        {notes.length === 0 && <Empty msg="No notes yet." />}
      </ul>
    </div>
  );
}
