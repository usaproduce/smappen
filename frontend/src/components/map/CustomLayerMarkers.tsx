import { useEffect, useRef, useState } from 'react';
import { useMapStore } from '../../stores/mapStore';
import { useProjectStore } from '../../stores/projectStore';
import {
  customLayersApi,
  type CustomLayer,
  type CustomLayerPoint,
} from '../../api/customLayers';

const PALETTE_COLOR: Record<string, string> = {
  viridis: '#7848BB',
  magma:   '#dc2626',
  plasma:  '#f59e0b',
  turbo:   '#2196f3',
};

/**
 * Renders all visible custom layers as colored circles on the map. Loads the
 * layer list whenever `customLayersVersion` bumps (LayersTab triggers this
 * after create/update/delete), then fetches points for each visible layer.
 *
 * One google.maps.Circle per point — keeps the radius-in-meters semantic
 * intuitive on the map. For very large point sets a marker clusterer would
 * be preferable; the backend caps at 50k per layer so this stays workable.
 */
export default function CustomLayerMarkers() {
  const mapInstance = useMapStore((s) => s.mapInstance);
  const customLayersVersion = useMapStore((s) => s.customLayersVersion);
  const currentProject = useProjectStore((s) => s.currentProject);
  const [layers, setLayers] = useState<CustomLayer[]>([]);
  const [pointsByLayer, setPointsByLayer] = useState<Record<string, CustomLayerPoint[]>>({});
  const circlesRef = useRef<google.maps.Circle[]>([]);

  useEffect(() => {
    if (!currentProject) {
      setLayers([]);
      setPointsByLayer({});
      return;
    }
    let cancelled = false;
    (async () => {
      try {
        const ls = await customLayersApi.list(currentProject.id);
        if (cancelled) return;
        setLayers(ls);
        const visibleLayers = ls.filter((l) => l.visible);
        const results = await Promise.all(
          visibleLayers.map(async (l) => {
            try {
              const { points } = await customLayersApi.points(l.id);
              return [l.id, points] as const;
            } catch {
              return [l.id, [] as CustomLayerPoint[]] as const;
            }
          })
        );
        if (cancelled) return;
        const map: Record<string, CustomLayerPoint[]> = {};
        for (const [id, pts] of results) map[id] = pts;
        setPointsByLayer(map);
      } catch {
        if (!cancelled) {
          setLayers([]);
          setPointsByLayer({});
        }
      }
    })();
    return () => { cancelled = true; };
  }, [currentProject?.id, customLayersVersion]);

  // Mirror the snapshot of visible custom layers into mapStore so the
  // screenshot composite can render the same circles on top of the
  // static-map base. Keeps the layers visually in-sync with the live map
  // without re-fetching from the API at export time.
  const setCustomLayerSnapshots = useMapStore((s) => s.setCustomLayerSnapshots);
  useEffect(() => {
    const snaps = layers
      .filter((l) => l.visible)
      .map((l) => ({
        id: l.id,
        color: PALETTE_COLOR[l.palette_id] ?? '#7848BB',
        radiusMeters: l.radius_meters,
        points: (pointsByLayer[l.id] ?? []).map((p) => ({ lat: p.lat, lng: p.lng })),
      }));
    setCustomLayerSnapshots(snaps);
    return () => { setCustomLayerSnapshots([]); };
  }, [layers, pointsByLayer, setCustomLayerSnapshots]);

  useEffect(() => {
    circlesRef.current.forEach((c) => c.setMap(null));
    circlesRef.current = [];
    if (!mapInstance) return;
    for (const layer of layers) {
      if (!layer.visible) continue;
      const points = pointsByLayer[layer.id] ?? [];
      const color = PALETTE_COLOR[layer.palette_id] ?? '#7848BB';
      for (const p of points) {
        const circle = new google.maps.Circle({
          map: mapInstance,
          center: { lat: p.lat, lng: p.lng },
          radius: layer.radius_meters,
          fillColor: color,
          fillOpacity: 0.25,
          strokeColor: color,
          strokeOpacity: 0.85,
          strokeWeight: 1,
          clickable: false,
        });
        circlesRef.current.push(circle);
      }
    }
    return () => {
      circlesRef.current.forEach((c) => c.setMap(null));
      circlesRef.current = [];
    };
  }, [mapInstance, layers, pointsByLayer]);

  return null;
}
