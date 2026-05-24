import { useEffect, useRef, useState } from 'react';
import toast from 'react-hot-toast';
import { Camera, X as XIcon } from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import { fieldNoteApi, type FieldNote } from '../../api/advanced';
import { api } from '../../api/client';
import { Empty } from './shared';

export default function FieldTab({ projectId }: { projectId: string }) {
  const [notes, setNotes] = useState<FieldNote[]>([]);
  const [body, setBody] = useState('');
  const [pos, setPos] = useState<{ lat: number; lng: number; accuracy?: number } | null>(null);
  const [photoUrl, setPhotoUrl] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [uploading, setUploading] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);
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

  async function onPhotoPick(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploading(true);
    try {
      const form = new FormData();
      form.append('file', file);
      const { data } = await api.post('/api/uploads?kind=field_note_photo', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setPhotoUrl(data.data.url);
      toast.success('Photo attached');
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Upload failed');
    } finally {
      setUploading(false);
      // Reset input so the same file can be re-picked if needed.
      if (fileInputRef.current) fileInputRef.current.value = '';
    }
  }

  async function save() {
    if (!body.trim() || !pos) return;
    setBusy(true);
    try {
      await fieldNoteApi.create(projectId, {
        body: body.trim(), lat: pos.lat, lng: pos.lng,
        accuracy_m: pos.accuracy,
        photo_url: photoUrl ?? undefined,
      } as any);
      setBody(''); setPhotoUrl(null);
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

      {/* Photo attachment — `capture="environment"` opens the back camera
          directly on mobile instead of the photo picker. */}
      <div>
        <input
          ref={fileInputRef}
          type="file"
          accept="image/*"
          capture="environment"
          onChange={onPhotoPick}
          className="hidden"
        />
        {!photoUrl ? (
          <button
            type="button"
            onClick={() => fileInputRef.current?.click()}
            disabled={uploading}
            className="btn btn-secondary h-9 w-full text-xs inline-flex items-center justify-center gap-1.5"
          >
            <Camera size={14} /> {uploading ? 'Uploading…' : 'Attach photo (optional)'}
          </button>
        ) : (
          <div className="relative inline-block w-full">
            <img src={photoUrl} alt="attachment" className="w-full h-32 object-cover rounded-lg border border-slate-200" />
            <button
              type="button"
              onClick={() => setPhotoUrl(null)}
              className="absolute top-1.5 right-1.5 bg-white/90 rounded-full p-1 shadow hover:bg-white"
              title="Remove photo"
            >
              <XIcon size={12} />
            </button>
          </div>
        )}
      </div>

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
            {n.photo_url && (
              <a href={n.photo_url} target="_blank" rel="noreferrer" className="block mt-1">
                <img src={n.photo_url} alt="" className="w-full h-24 object-cover rounded border border-slate-200" />
              </a>
            )}
            <div className="text-slate-500 mt-0.5">{n.lat.toFixed(5)}, {n.lng.toFixed(5)}</div>
          </li>
        ))}
        {notes.length === 0 && <Empty msg="No notes yet." />}
      </ul>
    </div>
  );
}
