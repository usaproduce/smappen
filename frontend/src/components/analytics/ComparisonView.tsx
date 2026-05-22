import { useQuery } from '@tanstack/react-query';
import { areasApi } from '../../api/areas';
import { formatNumber, formatCurrency } from '../../utils/format';

export default function ComparisonView({ areaIds, onClose }: { areaIds: string[]; onClose: () => void }) {
  const { data, isLoading } = useQuery({
    queryKey: ['compare', areaIds.join(',')],
    queryFn: () => areasApi.compareDemographics(areaIds),
    enabled: areaIds.length > 0,
  });

  const metrics = [
    { label: 'Population', extract: (d: any) => formatNumber(d?.population?.total) },
    { label: 'Median Income', extract: (d: any) => formatCurrency(d?.income?.median_household) },
    { label: 'Unemployment', extract: (d: any) => `${d?.employment?.unemployment_rate ?? 0}%` },
    { label: 'Housing Units', extract: (d: any) => formatNumber(d?.housing?.total_units) },
    { label: 'Median Home Value', extract: (d: any) => formatCurrency(d?.housing?.median_value) },
  ];

  return (
    <div className="fixed inset-0 bg-black/30 flex items-center justify-center z-50 p-4" onClick={onClose}>
      <div className="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-auto" onClick={(e) => e.stopPropagation()}>
        <div className="p-4 border-b border-slate-100 flex justify-between items-center">
          <h2 className="font-bold">Compare Areas</h2>
          <button className="btn btn-ghost" onClick={onClose}>Close</button>
        </div>
        <div className="p-4">
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
