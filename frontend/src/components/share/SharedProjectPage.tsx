import { useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import { GoogleMap, useJsApiLoader, Polygon } from '@react-google-maps/api';
import axios from 'axios';

interface Area {
  id: string;
  name: string;
  fill_color: string;
  stroke_color: string;
  fill_opacity: number;
  geometry: { type: 'Polygon'; coordinates: number[][][] };
  demographics?: any;
}
interface Payload {
  project: { id: string; name: string; description: string | null; center_lat: number; center_lng: number; zoom_level: number };
  areas: Area[];
  view_count: number;
}

const LIBRARIES: any[] = ['drawing', 'visualization', 'geometry', 'places'];

/**
 * Read-only public view of a shared project. No auth — backend validates
 * the share_token. Useful for sales/franchise development handoffs.
 */
export default function SharedProjectPage() {
  const { token } = useParams<{ token: string }>();
  const [data, setData] = useState<Payload | null>(null);
  const [error, setError] = useState<string | null>(null);

  const apiKey = (import.meta as any).env?.VITE_GOOGLE_MAPS_API_KEY ?? '';
  const { isLoaded } = useJsApiLoader({ googleMapsApiKey: apiKey, libraries: LIBRARIES });

  useEffect(() => {
    if (!token) return;
    (async () => {
      try {
        const r = await axios.get(`/api/public/projects/${encodeURIComponent(token)}`);
        setData(r.data.data);
      } catch (e: any) {
        setError(e?.response?.data?.error ?? 'Could not load this share link');
      }
    })();
  }, [token]);

  const polygons = useMemo(() => {
    if (!data) return [];
    return data.areas.map((a) => {
      const path = (a.geometry?.coordinates?.[0] ?? []).map(([lng, lat]) => ({ lat, lng }));
      return { id: a.id, name: a.name, path, fill: a.fill_color, stroke: a.stroke_color, opacity: a.fill_opacity };
    });
  }, [data]);

  if (error) {
    return <div className="min-h-screen flex items-center justify-center text-rose-700 bg-slate-50">{error}</div>;
  }
  if (!data || !isLoaded) {
    return <div className="min-h-screen flex items-center justify-center text-slate-500">Loading…</div>;
  }

  return (
    <div className="min-h-screen flex flex-col">
      <header className="bg-white border-b border-slate-200 px-4 py-3">
        <h1 className="font-extrabold text-lg" style={{ color: '#1A1A2E' }}>{data.project.name}</h1>
        {data.project.description && <p className="text-sm text-slate-600">{data.project.description}</p>}
        <p className="text-xs text-slate-400 mt-0.5">Shared via Smappen · {data.areas.length} area{data.areas.length === 1 ? '' : 's'} · {data.view_count} views</p>
      </header>
      <div className="flex-1 relative">
        <GoogleMap
          mapContainerStyle={{ width: '100%', height: '100%' }}
          center={{ lat: data.project.center_lat ?? 39.5, lng: data.project.center_lng ?? -98.5 }}
          zoom={data.project.zoom_level ?? 5}
          options={{ disableDefaultUI: false, streetViewControl: false, mapTypeControl: false }}
        >
          {polygons.map((p) => (
            <Polygon
              key={p.id}
              paths={p.path}
              options={{
                fillColor: p.fill,
                fillOpacity: p.opacity,
                strokeColor: p.stroke,
                strokeWeight: 2,
                clickable: false,
              }}
            />
          ))}
        </GoogleMap>
      </div>
    </div>
  );
}
