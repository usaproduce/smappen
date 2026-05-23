import { useEffect, useRef } from 'react';
import { useMapStore } from '../../stores/mapStore';
import { heatmapApi, type HeatmapMetric, type HeatmapResponse } from '../../api/heatmap';
import { colorForValue } from '../../utils/heatmapColors';

interface Props {
  metric: HeatmapMetric;
  onMetaChange?: (meta: HeatmapResponse['meta']) => void;
}

export default function ChoroplethLayer({ metric, onMetaChange }: Props) {
  const { mapInstance } = useMapStore();
  const dataLayerRef = useRef<google.maps.Data | null>(null);
  const rangeRef = useRef<{ min: number; max: number; breaks?: number[] }>({ min: 0, max: 1 });
  const fetchTokenRef = useRef(0);
  const debounceRef = useRef<number | null>(null);
  const prefetchTimerRef = useRef<number | null>(null);

  useEffect(() => {
    if (!mapInstance || typeof google === 'undefined') return;

    const layer = new google.maps.Data({ map: mapInstance });
    dataLayerRef.current = layer;

    function applyStyle() {
      layer.setStyle((f) => {
        const v = f.getProperty('value') as number | null;
        return {
          fillColor: colorForValue(v, rangeRef.current.min, rangeRef.current.max, rangeRef.current.breaks),
          fillOpacity: 0.55,
          strokeColor: '#ffffff',
          strokeWeight: 0.5,
          clickable: false,
        };
      });
    }
    applyStyle();

    async function load() {
      const bounds = mapInstance!.getBounds();
      if (!bounds) return;
      const ne = bounds.getNorthEast();
      const sw = bounds.getSouthWest();
      const zoom = mapInstance!.getZoom() ?? 10;
      const bbox: [number, number, number, number] = [sw.lng(), sw.lat(), ne.lng(), ne.lat()];
      const token = ++fetchTokenRef.current;
      try {
        const res = await heatmapApi.tracts(bbox, metric, zoom);
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
          heatmapApi.prefetchAdjacent(bbox, metric, zoom);
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
      if (debounceRef.current) window.clearTimeout(debounceRef.current);
      if (prefetchTimerRef.current) window.clearTimeout(prefetchTimerRef.current);
      const snapshot: google.maps.Data.Feature[] = [];
      layer.forEach((f) => snapshot.push(f));
      snapshot.forEach((f) => layer.remove(f));
      layer.setMap(null);
      dataLayerRef.current = null;
    };
  }, [mapInstance, metric]);

  return null;
}
