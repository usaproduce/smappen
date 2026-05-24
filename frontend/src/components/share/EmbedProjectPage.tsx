import { useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import { GoogleMap, useJsApiLoader, Polygon } from '@react-google-maps/api';
import axios from 'axios';

interface EmbedPayload {
  project_id: string;
  name: string;
  center_lat: number;
  center_lng: number;
  zoom_level: number;
  areas: { id: string; name: string; fill_color: string; stroke_color: string; fill_opacity: number; geometry: any }[];
}

const LIBRARIES: any[] = ['geometry'];

/**
 * Iframe-friendly embed view at /embed/{token}. Minimal chrome: just the
 * map, polygons, and a small "Powered by Smappen" attribution link.
 * Backend payload from /api/public/projects/{token}/embed (geometry only,
 * no demographics — lighter than the full /share/ view).
 */
export default function EmbedProjectPage() {
  const { token } = useParams<{ token: string }>();
  const [data, setData] = useState<EmbedPayload | null>(null);
  const [error, setError] = useState<string | null>(null);

  const apiKey = (import.meta as any).env?.VITE_GOOGLE_MAPS_API_KEY ?? '';
  const { isLoaded } = useJsApiLoader({ googleMapsApiKey: apiKey, libraries: LIBRARIES });

  useEffect(() => {
    if (!token) return;
    (async () => {
      try {
        const r = await axios.get(`/api/public/projects/${encodeURIComponent(token)}/embed`);
        setData(r.data.data);
      } catch (e: any) {
        setError(e?.response?.data?.error ?? 'Could not load this embed');
      }
    })();
  }, [token]);

  // Defensive geometry parsing — skip malformed polygons rather than blanking
  // the whole embed if one area has corrupt coords.
  const polygons = useMemo(() => {
    if (!data) return [];
    return data.areas
      .map((a) => {
        const ring = a.geometry?.coordinates?.[0];
        if (!Array.isArray(ring) || ring.length < 3) return null;
        const path: google.maps.LatLngLiteral[] = [];
        for (const pt of ring) {
          if (Array.isArray(pt) && pt.length >= 2 && Number.isFinite(pt[0]) && Number.isFinite(pt[1])) {
            path.push({ lat: pt[1], lng: pt[0] });
          }
        }
        return path.length >= 3 ? { id: a.id, name: a.name, path, fill: a.fill_color, stroke: a.stroke_color, opacity: a.fill_opacity } : null;
      })
      .filter(Boolean) as any[];
  }, [data]);

  if (error) {
    return <div className="h-screen flex items-center justify-center text-sm text-rose-700 bg-slate-50">{error}</div>;
  }
  if (!data || !isLoaded) {
    return <div className="h-screen flex items-center justify-center text-sm text-slate-500">Loading…</div>;
  }

  return (
    <div className="h-screen w-screen relative">
      <GoogleMap
        mapContainerStyle={{ width: '100%', height: '100%' }}
        center={{ lat: data.center_lat ?? 39.5, lng: data.center_lng ?? -98.5 }}
        zoom={data.zoom_level ?? 5}
        options={{
          // Minimal chrome — embed is meant for inline display, not interaction.
          disableDefaultUI: true,
          zoomControl: true,
          gestureHandling: 'cooperative',
        }}
      >
        {polygons.map((p: any) => (
          <Polygon
            key={p.id}
            paths={p.path}
            options={{
              fillColor: p.fill,
              fillOpacity: p.opacity ?? 0.3,
              strokeColor: p.stroke,
              strokeWeight: 2,
              clickable: false,
            }}
          />
        ))}
      </GoogleMap>
      {/* Tiny attribution badge — keeps Smappen branded on every embedded view */}
      <a
        href="https://smappen.mygreendock.com"
        target="_blank"
        rel="noreferrer"
        className="absolute bottom-2 right-2 bg-white/90 backdrop-blur px-2 py-1 rounded shadow text-[10px] font-bold uppercase tracking-wider hover:bg-white"
        style={{ color: '#7848BB' }}
      >
        Powered by Smappen
      </a>
    </div>
  );
}
