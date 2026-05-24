import { useEffect, useRef, useState } from 'react';
import { OverlayView } from '@react-google-maps/api';
import { useProjectStore } from '../../stores/projectStore';
import { useMapStore } from '../../stores/mapStore';
import { useAuthStore } from '../../stores/authStore';

interface Peer {
  user_id: string;
  user_name: string;
  lat: number;
  lng: number;
  selected_area_id?: string | null;
  last_seen: number;
}

const PALETTE = ['#7848BB', '#1D9E75', '#E53935', '#378ADD', '#EF9F27', '#D85A30', '#0EA5E9', '#F472B6'];
function pickColor(userId: string): string {
  let h = 0;
  for (let i = 0; i < userId.length; i++) h = (h * 31 + userId.charCodeAt(i)) >>> 0;
  return PALETTE[h % PALETTE.length];
}

/**
 * #13 — render other-user cursors on the map. Two effects:
 *   1. POST /presence/ping every ~750ms with the user's mouse position (throttled).
 *   2. EventSource → /presence/stream to receive the peer list.
 *
 * Inactive on solo projects (peers list empty) so nothing visible changes.
 * Auto-reconnects on EventSource error after a 2s backoff.
 */
export default function PresenceCursors() {
  const projectId = useProjectStore((s) => s.currentProject?.id);
  const mapInstance = useMapStore((s) => s.mapInstance);
  const userId = useAuthStore((s) => (s.user as any)?.id);
  const [peers, setPeers] = useState<Peer[]>([]);
  const lastPing = useRef(0);
  const lastPos = useRef<{ lat: number; lng: number } | null>(null);

  // Ping loop — listens to map mousemove + flushes the latest position
  // every 750ms (so we don't hammer the backend at 60fps).
  useEffect(() => {
    if (!projectId || !mapInstance) return;
    const handler = (e: google.maps.MapMouseEvent) => {
      if (!e.latLng) return;
      lastPos.current = { lat: e.latLng.lat(), lng: e.latLng.lng() };
      const now = Date.now();
      if (now - lastPing.current < 750) return;
      lastPing.current = now;
      fetch(`/api/projects/${projectId}/presence/ping`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer ' + (useAuthStore.getState().token ?? ''),
        },
        body: JSON.stringify(lastPos.current),
      }).catch(() => {});
    };
    const listener = mapInstance.addListener('mousemove', handler);
    return () => google.maps.event.removeListener(listener);
  }, [projectId, mapInstance]);

  // SSE peer stream — reconnects on error, drops the channel on unmount.
  useEffect(() => {
    if (!projectId) return;
    let es: EventSource | null = null;
    let retry: number | undefined;
    let cancelled = false;

    function open() {
      if (cancelled) return;
      // Pass token via query because EventSource ignores headers.
      const token = useAuthStore.getState().token ?? '';
      es = new EventSource(`/api/projects/${projectId}/presence/stream?token=${encodeURIComponent(token)}`);
      es.onmessage = (ev) => {
        try {
          const data = JSON.parse(ev.data);
          if (Array.isArray(data.peers)) setPeers(data.peers);
        } catch {}
      };
      es.onerror = () => {
        es?.close();
        if (!cancelled) retry = window.setTimeout(open, 2000);
      };
    }
    open();
    return () => {
      cancelled = true;
      es?.close();
      window.clearTimeout(retry);
    };
  }, [projectId]);

  if (!peers.length) return null;
  return (
    <>
      {peers.filter((p) => p.user_id !== userId).map((p) => (
        <OverlayView
          key={p.user_id}
          position={{ lat: p.lat, lng: p.lng }}
          mapPaneName={OverlayView.OVERLAY_MOUSE_TARGET}
        >
          <CursorChip name={p.user_name} color={pickColor(p.user_id)} />
        </OverlayView>
      ))}
    </>
  );
}

function CursorChip({ name, color }: { name: string; color: string }) {
  return (
    <div className="pointer-events-none" style={{ transform: 'translate(-3px, -3px)' }}>
      <svg width="20" height="20" viewBox="0 0 20 20" style={{ filter: 'drop-shadow(0 1px 2px rgba(0,0,0,0.2))' }}>
        <path d="M0 0 L0 14 L4.5 10.5 L7.5 16 L9.5 15 L6.5 9.5 L12 9.5 Z" fill={color} stroke="#fff" strokeWidth="1" />
      </svg>
      <div
        className="absolute left-4 top-3 px-1.5 py-0.5 rounded text-[10px] font-bold whitespace-nowrap shadow-sm"
        style={{ background: color, color: '#fff' }}
      >
        {name}
      </div>
    </div>
  );
}
