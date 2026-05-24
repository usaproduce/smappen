import { useQuery } from '@tanstack/react-query';
import { areasApi } from '../../api/areas';
import { formatNumber, formatCurrency } from '../../utils/format';
import RadarChart from './RadarChart';

const SERIES_COLORS = ['#7848BB', '#1D9E75', '#E53935', '#378ADD', '#EF9F27'];

export default function ComparisonView({ areaIds, onClose }: { areaIds: string[]; onClose: () => void }) {
  const { data, isLoading } = useQuery({
    queryKey: ['compare', areaIds.join(',')],
    queryFn: () => areasApi.compareDemographics(areaIds),
    enabled: areaIds.length > 0,
  });

  const metrics = [
    { label: 'Population',        extract: (d: any) => formatNumber(d?.population?.total),         raw: (d: any) => d?.population?.total ?? 0 },
    { label: 'Median Income',     extract: (d: any) => formatCurrency(d?.income?.median_household), raw: (d: any) => d?.income?.median_household ?? 0 },
    { label: 'Unemployment',      extract: (d: any) => `${d?.employment?.unemployment_rate ?? 0}%`, raw: (d: any) => d?.employment?.unemployment_rate ?? 0 },
    { label: 'Housing Units',     extract: (d: any) => formatNumber(d?.housing?.total_units),       raw: (d: any) => d?.housing?.total_units ?? 0 },
    { label: 'Median Home Value', extract: (d: any) => formatCurrency(d?.housing?.median_value),    raw: (d: any) => d?.housing?.median_value ?? 0 },
  ];

  // VT6 — compute B vs A delta per metric for the sticky delta bar.
  function pctDelta(a: number, b: number) {
    if (!a) return null;
    return ((b - a) / a) * 100;
  }

  return (
    <div className="fixed inset-0 bg-black/30 flex items-center justify-center z-50 p-4" onClick={onClose}>
      <div className="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-auto" onClick={(e) => e.stopPropagation()}>
        <div className="p-4 border-b border-slate-100 flex justify-between items-center">
          <h2 className="font-bold">Compare Areas</h2>
          <button className="btn btn-ghost" onClick={onClose}>Close</button>
        </div>
        {/* VT6 — sticky delta bar at the top. Shows percentage difference of
            B vs A for the headline metrics so the comparison is digestible
            without reading every row of the table below. */}
        {data && data.length === 2 && (
          <div className="sticky top-0 z-10 px-4 py-2 bg-gradient-to-b from-violet-50 to-white border-b border-violet-100 flex flex-wrap items-center gap-3 text-xs">
            <span className="text-[10px] uppercase tracking-wider font-bold text-violet-700">B vs A</span>
            {metrics.map((m) => {
              const a = m.raw(data[0].demographics);
              const b = m.raw(data[1].demographics);
              const d = pctDelta(a, b);
              if (d == null) return null;
              const sign = d > 0 ? '+' : '';
              const color = d > 0 ? '#1D9E75' : d < 0 ? '#D85A30' : '#6B6B7B';
              return (
                <span key={m.label} className="flex items-center gap-1">
                  <span className="text-slate-500">{m.label}:</span>
                  <span className="font-bold tabular-nums" style={{ color }}>{sign}{d.toFixed(1)}%</span>
                </span>
              );
            })}
          </div>
        )}
        <div className="p-4">
          {/* Radar overlay — shown when we have at least 2 areas. Each metric
              is normalized by its max value across the set, so areas are
              compared relative to each other rather than against an absolute
              ceiling. Unemployment is inverted (lower = better). */}
          {data && data.length >= 2 && (
            <div className="mb-6 flex flex-col md:flex-row items-center gap-6 bg-slate-50 rounded-lg p-4 border border-slate-100">
              {(() => {
                const axes = metrics.map((m) => m.label);
                const matrix = data.map((d) => metrics.map((m) => m.raw(d.demographics)));
                const maxes = metrics.map((_, i) => Math.max(1, ...matrix.map((row) => row[i])));
                const series = data.map((d, di) => ({
                  label: d.area_name,
                  color: SERIES_COLORS[di % SERIES_COLORS.length],
                  values: metrics.map((m, mi) => {
                    const raw = m.raw(d.demographics);
                    // Unemployment: invert so a lower value reads as "better" on the radar.
                    if (m.label === 'Unemployment') return raw > 0 ? 1 - Math.min(1, raw / 25) : 1;
                    return raw / maxes[mi];
                  }),
                }));
                return <RadarChart axes={axes} series={series} size={300} />;
              })()}
              <div className="space-y-1.5 text-xs">
                {data.map((d, i) => (
                  <div key={d.area_id} className="flex items-center gap-2 font-semibold" style={{ color: '#1A1A2E' }}>
                    <span className="inline-block w-3 h-3 rounded-sm" style={{ background: SERIES_COLORS[i % SERIES_COLORS.length] }} />
                    {d.area_name}
                  </div>
                ))}
                <div className="text-[10px] text-slate-500 mt-2 max-w-[180px]">
                  Values normalized to the highest in the set. Unemployment inverted (further out = lower rate).
                </div>
              </div>
            </div>
          )}

          {isLoading || !data ? <div className="skeleton h-40" /> : (
            <table className="w-full text-sm">
              <thead>
                <tr>
                  <th className="text-left py-2">Metric</th>
                  {data.map((d) => <th key={d.area_id} className="text-left py-2">{d.area_name}</th>)}
                </tr>
              </thead>
              <tbody>
                {metrics.map((m) => (
                  <tr key={m.label} className="border-t border-slate-100">
                    <td className="py-2 font-semibold text-slate-600">{m.label}</td>
                    {data.map((d) => <td key={d.area_id} className="py-2">{m.extract(d.demographics)}</td>)}
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  );
}
