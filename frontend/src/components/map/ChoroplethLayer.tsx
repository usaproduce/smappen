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
  const fetchTokenRef = useRef(0);

  useEffect(() => {
    if (!mapInstance) return;

    const layer = new google.maps.Data({ map: mapInstance });
    dataLayerRef.current = layer;

    let min = 0, max = 1;
    layer.setStyle((f) => {
      const v = f.getProperty('value') as number | null;
      const color = colorForValue(v, min, max);
      return {
        fillColor: color,
        fillOpacity: 0.55,
        strokeColor: '#ffffff',
        strokeWeight: 0.5,
        clickable: false,
      };
    });

    async function load() {
      const bounds = mapInstance!.getBounds();
      if (!bounds) return;
      const ne = bounds.getNorthEast();
      const sw = bounds.getSouthWest();
      const bbox: [number, number, number, number] = [sw.lng(), sw.lat(), ne.lng(), ne.lat()];
      const token = ++fetchTokenRef.current;
      try {
        const res = await heatmapApi.tracts(bbox, metric);
        if (token !== fetchTokenRef.current) return;
        layer.forEach((f) => layer.remove(f));
        if (res.features.length === 0) {
          onMetaChange?.(res.meta);
          return;
        }
        min = res.meta.min;
        max = res.meta.max;
        layer.addGeoJson({ type: 'FeatureCollection', features: res.features });
        onMetaChange?.(res.meta);
      } catch (e) {
        // soft-fail; the panel will show "no data"
      }
    }

    const idleListener = mapInstance.addListener('idle', load);
    load();

    return () => {
      google.maps.event.removeListener(idleListener);
      layer.setMap(null);
      dataLayerRef.current = null;
    };
  }, [mapInstance, metric]);

  return null;
}
