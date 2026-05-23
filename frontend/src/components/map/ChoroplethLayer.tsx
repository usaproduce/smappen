import { useEffect, useRef } from 'react';
import { useMapStore } from '../../stores/mapStore';
import { heatmapApi, type HeatmapMetric, type HeatmapResponse } from '../../api/heatmap';
import { colorForValueWith, paletteById } from '../../utils/heatmapColors';

interface Props {
  metric: HeatmapMetric;
  onMetaChange?: (meta: HeatmapResponse['meta']) => void;
}

export default function ChoroplethLayer({ metric, onMetaChange }: Props) {
  const { mapInstance, heatmapLevel, heatmapPaletteId, setHoveredHeatmap } = useMapStore();
  const dataLayerRef = useRef<google.maps.Data | null>(null);
  const rangeRef = useRef<{ min: number; max: number; breaks?: number[] }>({ min: 0, max: 1 });
  const fetchTokenRef = useRef(0);
  const debounceRef = useRef<number | null>(null);
  const prefetchTimerRef = useRef<number | null>(null);

  useEffect(() => {
    if (!mapInstance || typeof google === 'undefined') return;

    const layer = new google.maps.Data({ map: mapInstance });
    dataLayerRef.current = layer;

    const palette = paletteById(heatmapPaletteId);
    function applyStyle() {
      layer.setStyle((f) => {
        const v = f.getProperty('value') as number | null;
        return {
          fillColor: colorForValueWith(palette, v, rangeRef.current.min, rangeRef.current.max, rangeRef.current.breaks),
          fillOpacity: 0.6,
          strokeColor: '#ffffff',
          strokeWeight: 0.5,
          clickable: true,
        };
      });
    }
    applyStyle();

    // Hover handlers — surface the hovered tract's value to the HeatmapPanel so it
    // can draw a position marker on the legend gradient.
    const overListener = layer.addListener('mouseover', (e: any) => {
      const f = e.feature as google.maps.Data.Feature;
      const v = f.getProperty('value') as number | null;
      const name = (f.getProperty('name') as string | null) ?? (f.getProperty('geoid') as string | null);
      setHoveredHeatmap(v, name);
      // Subtle hover highlight
      layer.overrideStyle(f, { strokeWeight: 2, strokeColor: '#1A1A2E' });
    });
    const outListener = layer.addListener('mouseout', (e: any) => {
      setHoveredHeatmap(null, null);
      layer.revertStyle(e.feature);
    });

    async function load() {
      const bounds = mapInstance!.getBounds();
      if (!bounds) return;
      const ne = bounds.getNorthEast();
      const sw = bounds.getSouthWest();
      const zoom = mapInstance!.getZoom() ?? 10;
      const bbox: [number, number, number, number] = [sw.lng(), sw.lat(), ne.lng(), ne.lat()];
      const token = ++fetchTokenRef.current;
      try {
        // 50K is well above the data we have loaded; effectively no truncation.
        // Server-side 7-day cache makes repeat fetches instant regardless of payload size.
        const res = await heatmapApi.tracts(bbox, metric, zoom, 50000, heatmapLevel);
        if (token !== fetchTokenRef.current) return; // stale response from a previous pan
        const snapshot: google.maps.Data.Feature[] = [];
        layer.forEach((f) => snapshot.push(f));
        snapshot.forEach((f) => layer.remove(f));
        if (res.features.length > 0) {
          rangeRef.current = { min: res.meta.min, max: res.meta.max, breaks: res.meta.breaks };
          layer.addGeoJson({ type: 'FeatureCollection', features: res.features });
          applyStyle();
        }
        onMetaChange?.(res.meta);

        // Once the user idles for ~1s, warm the cache with surrounding viewports.
        if (prefetchTimerRef.current) window.clearTimeout(prefetchTimerRef.current);
        prefetchTimerRef.current = window.setTimeout(() => {
          heatmapApi.prefetchAdjacent(bbox, metric, zoom, heatmapLevel);
        }, 1000);
      } catch {
        /* soft-fail */
      }
    }

    function scheduleLoad() {
      if (debounceRef.current) window.clearTimeout(debounceRef.current);
      debounceRef.current = window.setTimeout(load, 250);
    }

    const idleListener = mapInstance.addListener('idle', scheduleLoad);
    load();

    return () => {
      google.maps.event.removeListener(idleListener);
      google.maps.event.removeListener(overListener);
      google.maps.event.removeListener(outListener);
      if (debounceRef.current) window.clearTimeout(debounceRef.current);
      if (prefetchTimerRef.current) window.clearTimeout(prefetchTimerRef.current);
      const snapshot: google.maps.Data.Feature[] = [];
      layer.forEach((f) => snapshot.push(f));
      snapshot.forEach((f) => layer.remove(f));
      layer.setMap(null);
      dataLayerRef.current = null;
    };
  }, [mapInstance, metric, heatmapLevel, heatmapPaletteId]);

  return null;
}
