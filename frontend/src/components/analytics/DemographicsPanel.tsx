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
    // Tweak #24 — skeleton matches the final layout instead of three generic
    // bars, so the transition into loaded data is barely perceptible.
    return (
      <div className="p-4 space-y-4">
        <div className="space-y-2">
          <div className="skeleton skeleton-line w-1/3" style={{ height: 10 }} />
          <div className="skeleton" style={{ height: 36, width: '60%' }} />
          <div className="skeleton" style={{ height: 8, width: '100%' }} />
          <div className="flex gap-1">
            {[...Array(5)].map((_, i) => <div key={i} className="skeleton flex-1" style={{ height: 22 }} />)}
          </div>
        </div>
        <div className="skeleton skeleton-rect-md" />
        <div className="skeleton skeleton-rect-md" />
        <div className="skeleton skeleton-rect-sm w-1/2" />
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

  // Tweak #12 — hero number + inline age distribution mini-bar. The five
  // age buckets render as a single segmented bar so the user reads the
  // demographic skew at a glance before scrolling.
  const ageData = [
    { label: 'Under 18', value: data.age.under_18 ?? 0, color: '#a855f7' },
    { label: '18–34',    value: data.age['18_to_34'] ?? 0, color: '#7848BB' },
    { label: '35–54',    value: data.age['35_to_54'] ?? 0, color: '#3b82f6' },
    { label: '55–64',    value: data.age['55_to_64'] ?? 0, color: '#f59e0b' },
    { label: '65+',      value: data.age['65_plus'] ?? 0, color: '#ef4444' },
  ];
  const ageTotal = ageData.reduce((s, x) => s + x.value, 0) || 1;

  // Bracket data is suspect if the buckets sum to ~zero households but the
  // median income reads as positive — that's the symptom of the underlying
  // census ingestion mapping each bucket to a single ACS sub-bracket
  // instead of summing all sub-brackets in the range (see CensusService
  // VARIABLES). Until a re-ingest lands, drop the chart in that case
  // rather than rendering a misleading flat bar at $100K+.
  const bracketSum = (data.income.brackets.under_25k ?? 0)
    + (data.income.brackets['25k_to_50k'] ?? 0)
    + (data.income.brackets['50k_to_75k'] ?? 0)
    + (data.income.brackets['75k_to_100k'] ?? 0)
    + (data.income.brackets['100k_plus'] ?? 0);
  const bracketsOk = bracketSum > 0 && totalPop > 0 && bracketSum >= totalPop * 0.05;

  return (
    <div className="p-4 space-y-4">
      {/* Hero: 42px population number + density caption + age-bar. */}
      <div>
        <SectionLabel>Population</SectionLabel>
        <div className="font-extrabold leading-none mt-1" style={{ color: '#1e3a5f', fontSize: 42 }}>
          {formatNumber(totalPop)}
        </div>
        <div className="text-xs text-slate-700 mt-1 font-semibold">
          {formatNumber(data.population.density_per_sq_km)} per km² · {malePct.toFixed(0)}% M / {femalePct.toFixed(0)}% F
        </div>
        <div className="flex h-2 rounded-full overflow-hidden mt-3" title="Male / female split">
          <div style={{ width: `${malePct}%`,   background: '#3b82f6' }} />
          <div style={{ width: `${femalePct}%`, background: '#ec4899' }} />
        </div>
        <div className="mt-3">
          <SectionLabel className="mb-1">Age distribution</SectionLabel>
          <div className="flex h-3 rounded-full overflow-hidden" role="img" aria-label="Age distribution">
            {ageData.map((seg) => (
              <div
                key={seg.label}
                style={{ width: `${(seg.value / ageTotal) * 100}%`, background: seg.color }}
                title={`${seg.label}: ${formatNumber(seg.value)} (${((seg.value / ageTotal) * 100).toFixed(0)}%)`}
              />
            ))}
          </div>
          <div className="flex justify-between mt-1 text-[10px] text-slate-700 font-bold">
            {ageData.map((seg) => (
              <span key={seg.label} className="flex items-center gap-0.5">
                <span className="inline-block w-1.5 h-1.5 rounded-full" style={{ background: seg.color }} />
                {seg.label}
              </span>
            ))}
          </div>
        </div>
      </div>

      <div className="card">
        <CardHeading>Median household income</CardHeading>
        <div className="text-2xl font-extrabold text-emerald-700 tabular-nums">{formatCurrency(data.income.median_household)}</div>
        {bracketsOk ? (
          <>
            <SectionLabel className="mt-3 mb-1">Bracket distribution</SectionLabel>
            <ChartWidgets.HorizontalBarChart
              data={[
                { name: '<$25K',    value: data.income.brackets.under_25k },
                { name: '$25–50K',  value: data.income.brackets['25k_to_50k'] },
                { name: '$50–75K',  value: data.income.brackets['50k_to_75k'] },
                { name: '$75–100K', value: data.income.brackets['75k_to_100k'] },
                { name: '$100K+',   value: data.income.brackets['100k_plus'] },
              ]}
            />
          </>
        ) : null}
      </div>

      <div className="card">
        <CardHeading>Employment</CardHeading>
        <div className="text-sm text-slate-800">Labor force: <b className="font-extrabold">{formatNumber(data.employment.labor_force)}</b></div>
        <div className="text-sm text-slate-800">
          Unemployment: <b className="font-extrabold" style={{ color: unempColor }}>{unemp}%</b>
        </div>
      </div>

      <div className="card">
        <CardHeading>Housing</CardHeading>
        <div className="text-sm text-slate-800">Units: <b className="font-extrabold">{formatNumber(data.housing.total_units)}</b></div>
        <div className="text-sm text-slate-800">Median value: <b className="font-extrabold">{formatCurrency(data.housing.median_value)}</b></div>
      </div>

      {data.meta?.note && <div className="text-xs text-amber-700 bg-amber-50 p-2 rounded">{data.meta.note}</div>}

      {/* Data-freshness footer — builds user trust by showing vintage. The
          data_year field comes from census_demographics; we treat anything
          older than 18 months as "stale" with an amber pill, otherwise quiet. */}
      <DataFreshnessFooter dataYear={data.meta?.data_year ?? (data as any).data_year} />
    </div>
  );
}

/**
 * Section label — small uppercase header inside a card. Previously these
 * were rendered at text-slate-500 / font-medium across the panel and read
 * as faded background labels. Bumped to slate-700 + font-bold so they
 * actually function as headings.
 */
function SectionLabel({ children, className = '' }: { children: React.ReactNode; className?: string }) {
  return (
    <div className={`text-[10px] uppercase tracking-wider font-bold text-slate-700 ${className}`}>
      {children}
    </div>
  );
}

/** Larger card headline — used for "Employment", "Housing", "Median household income". */
function CardHeading({ children }: { children: React.ReactNode }) {
  return (
    <div className="text-xs font-extrabold uppercase tracking-wider mb-1.5" style={{ color: '#1e3a5f' }}>
      {children}
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
