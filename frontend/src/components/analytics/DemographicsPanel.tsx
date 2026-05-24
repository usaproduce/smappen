import { useQuery } from '@tanstack/react-query';
import { areasApi } from '../../api/areas';
import { formatNumber, formatCurrency } from '../../utils/format';
import ChartWidgets from './ChartWidgets';

export default function DemographicsPanel({ areaId }: { areaId: string }) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['demographics', areaId],
    queryFn: () => areasApi.demographics(areaId),
  });

  if (isLoading) {
    return (
      <div className="p-4 space-y-3">
        <div className="skeleton h-20" />
        <div className="skeleton h-32" />
        <div className="skeleton h-24" />
      </div>
    );
  }
  if (error || !data) {
    return <div className="p-4 text-sm text-red-500">Failed to load demographics.</div>;
  }
  const totalPop = data.population?.total ?? 0;
  const malePct = totalPop > 0 ? (data.population.male / totalPop) * 100 : 0;
  const femalePct = totalPop > 0 ? (data.population.female / totalPop) * 100 : 0;

  const unemp = data.employment?.unemployment_rate ?? 0;
  const unempColor = unemp < 5 ? '#22c55e' : unemp < 8 ? '#f59e0b' : '#ef4444';

  return (
    <div className="p-4 space-y-4">
      <div className="card">
        <div className="text-xs text-slate-500 uppercase font-semibold">Total population</div>
        <div className="text-3xl font-bold" style={{ color: '#1e3a5f' }}>{formatNumber(totalPop)}</div>
        <div className="text-xs text-slate-500 mt-1">{formatNumber(data.population.density_per_sq_km)} per sq km</div>
        <div className="mt-3 flex h-3 rounded-full overflow-hidden">
          <div style={{ width: `${malePct}%`, background: '#3b82f6' }} />
          <div style={{ width: `${femalePct}%`, background: '#ec4899' }} />
        </div>
        <div className="flex justify-between text-xs mt-1 text-slate-500">
          <span>Male {malePct.toFixed(0)}%</span><span>Female {femalePct.toFixed(0)}%</span>
        </div>
      </div>

      <div className="card">
        <div className="font-semibold text-sm mb-2" style={{ color: '#1e3a5f' }}>Age distribution</div>
        <ChartWidgets.HorizontalBarChart
          data={[
            { name: 'Under 18', value: data.age.under_18 },
            { name: '18–34', value: data.age['18_to_34'] },
            { name: '35–54', value: data.age['35_to_54'] },
            { name: '55–64', value: data.age['55_to_64'] },
            { name: '65+', value: data.age['65_plus'] },
          ]}
        />
      </div>

      <div className="card">
        <div className="text-xs text-slate-500 uppercase font-semibold">Median household income</div>
        <div className="text-2xl font-bold text-emerald-700">{formatCurrency(data.income.median_household)}</div>
        <div className="mt-2 text-xs text-slate-500 mb-1">Bracket distribution</div>
        <ChartWidgets.HorizontalBarChart
          data={[
            { name: '<$25K', value: data.income.brackets.under_25k },
            { name: '$25–50K', value: data.income.brackets['25k_to_50k'] },
            { name: '$50–75K', value: data.income.brackets['50k_to_75k'] },
            { name: '$75–100K', value: data.income.brackets['75k_to_100k'] },
            { name: '$100K+', value: data.income.brackets['100k_plus'] },
          ]}
        />
      </div>

      <div className="card">
        <div className="font-semibold text-sm mb-1" style={{ color: '#1e3a5f' }}>Employment</div>
        <div className="text-sm">Labor force: <b>{formatNumber(data.employment.labor_force)}</b></div>
        <div className="text-sm">
          Unemployment: <b style={{ color: unempColor }}>{unemp}%</b>
        </div>
      </div>

      <div className="card">
        <div className="font-semibold text-sm mb-1" style={{ color: '#1e3a5f' }}>Housing</div>
        <div className="text-sm">Units: <b>{formatNumber(data.housing.total_units)}</b></div>
        <div className="text-sm">Median value: <b>{formatCurrency(data.housing.median_value)}</b></div>
      </div>

      {data.meta?.note && <div className="text-xs text-amber-700 bg-amber-50 p-2 rounded">{data.meta.note}</div>}

      {/* Data-freshness footer — builds user trust by showing vintage. The
          data_year field comes from census_demographics; we treat anything
          older than 18 months as "stale" with an amber pill, otherwise quiet. */}
      <DataFreshnessFooter dataYear={data.meta?.data_year ?? (data as any).data_year} />
    </div>
  );
}

function DataFreshnessFooter({ dataYear }: { dataYear?: number | null }) {
  if (!dataYear) return null;
  const monthsOld = (new Date().getFullYear() - dataYear) * 12;
  const stale = monthsOld > 18;
  return (
    <div className={`text-[10px] flex items-center gap-1.5 px-2 py-1.5 rounded-md ${stale ? 'bg-amber-50 text-amber-800 border border-amber-200' : 'text-slate-400'}`}>
      <span className="font-semibold">Source:</span>
      <span>US Census ACS {dataYear} (5-year estimates)</span>
      {stale && <span className="ml-auto font-bold uppercase tracking-wider">stale · {Math.round(monthsOld / 12)}y old</span>}
    </div>
  );
}
