import { useMemo } from 'react';
import { useProjectStore } from '../../stores/projectStore';

/**
 * OP18 — at-a-glance ribbon at the top of the left panel showing total
 * areas, total reach (population summed across areas with demographics),
 * and total favorites. Hidden when there are <3 areas (not worth the
 * visual noise on a near-empty project).
 */
export default function QuickStatsStrip() {
  const { areas } = useProjectStore() as any;
  const { totalAreas, totalReach, totalFavs, demoCovered } = useMemo(() => {
    let tr = 0; let cov = 0; let favs = 0;
    for (const a of areas ?? []) {
      const dc = a?.demographics_cache;
      const pop = dc?.population?.total ?? dc?.total_population ?? null;
      if (typeof pop === 'number' && pop > 0) { tr += pop; cov++; }
      if (a?.is_favorite) favs++;
    }
    return { totalAreas: areas?.length ?? 0, totalReach: tr, totalFavs: favs, demoCovered: cov };
  }, [areas]);

  if (totalAreas < 3) return null;
  return (
    <div className="px-3 pt-1.5 pb-2 border-b border-slate-100 grid grid-cols-3 gap-1.5">
      <Tile label="Areas" value={totalAreas.toLocaleString()} hint={demoCovered ? `${demoCovered} w/ demo` : undefined} />
      <Tile label="Reach" value={formatCompact(totalReach)} hint="people" />
      <Tile label="Faves" value={totalFavs.toLocaleString()} />
    </div>
  );
}

function Tile({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="bg-violet-50 border border-violet-100 rounded-md px-2 py-1.5">
      <div className="text-[9px] uppercase tracking-wider font-bold text-violet-700">{label}</div>
      <div className="text-sm font-extrabold leading-tight" style={{ color: '#1A1A2E' }}>{value}</div>
      {hint && <div className="text-[9px] text-violet-500/70 font-medium">{hint}</div>}
    </div>
  );
}

function formatCompact(n: number): string {
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M';
  if (n >= 1_000)     return (n / 1_000).toFixed(1).replace(/\.0$/, '') + 'K';
  return Math.round(n).toLocaleString();
}
