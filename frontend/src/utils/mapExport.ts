import toast from 'react-hot-toast';
import { collabApi } from '../api/advanced';

/**
 * Map screenshot (#36). Uses Google Static Maps API when the key is
 * available — it gives a clean PNG without all the floating UI on top of
 * an html2canvas capture. Falls back to a polite "open in Google Maps"
 * link otherwise.
 */
export async function downloadMapSnapshot(opts: {
  lat: number;
  lng: number;
  zoom: number;
  width?: number;
  height?: number;
  markers?: { lat: number; lng: number; color?: string }[];
  filename?: string;
}) {
  const key = (import.meta as any).env?.VITE_GOOGLE_MAPS_API_KEY ?? '';
  if (!key) {
    toast.error('Map key not configured for static export');
    return;
  }
  const w = Math.min(1280, opts.width ?? 1280);
  const h = Math.min(1280, opts.height ?? 720);
  const params = new URLSearchParams({
    center: `${opts.lat},${opts.lng}`,
    zoom: String(opts.zoom),
    size: `${w}x${h}`,
    scale: '2',
    maptype: 'roadmap',
    key,
  });
  for (const m of opts.markers ?? []) {
    params.append('markers', `color:${m.color ?? '0x7848BB'}|${m.lat},${m.lng}`);
  }
  const url = `https://maps.googleapis.com/maps/api/staticmap?${params.toString()}`;

  try {
    const resp = await fetch(url);
    if (!resp.ok) throw new Error('static maps ' + resp.status);
    const blob = await resp.blob();
    const objUrl = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = objUrl;
    a.download = opts.filename ?? `smappen-${Date.now()}.png`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(objUrl);
    toast.success('Map image downloaded');
  } catch (e: any) {
    toast.error('Could not export map');
  }
}

/** Project-level snapshot (versioning). Wired to the Cmd+S shortcut. */
export async function saveProjectSnapshot(projectId: string) {
  try {
    const r = await collabApi.snapshot(projectId, '');
    toast.success(`Saved v${r.version_number}`);
  } catch (e: any) {
    toast.error(e?.response?.data?.error ?? 'Snapshot failed');
  }
}
