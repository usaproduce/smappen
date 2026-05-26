import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Plus, MapPin } from 'lucide-react';
import { carafeApi } from '../../api/carafe';
import EmptyState from '../common/EmptyState';

/** /admin/carafe/campaigns — table of every seed campaign, newest first. */
export default function SeedCampaignsListPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['carafe', 'campaigns', 'list'],
    queryFn: () => carafeApi.listCampaigns(50, 0),
    refetchInterval: 15_000,
  });

  return (
    <div>
      <div className="flex items-end justify-between mb-6">
        <h1 className="text-2xl font-extrabold text-slate-900">Campaigns</h1>
        <Link to="/admin/carafe/campaigns/new" className="btn btn-primary h-10 px-4 text-sm">
          <Plus size={14} /> New campaign
        </Link>
      </div>

      {isLoading ? (
        <div className="text-slate-500 text-sm">Loading…</div>
      ) : !data || data.campaigns.length === 0 ? (
        <div className="bg-white border border-slate-200 rounded-lg">
          <EmptyState
            icon={<MapPin size={32} />}
            title="No campaigns yet"
            subtitle="Define a geography + vendor types, see the cost estimate, then run."
          />
          <div className="px-8 pb-8 text-center">
            <Link to="/admin/carafe/campaigns/new" className="btn btn-primary h-9 px-4 text-sm">
              New campaign
            </Link>
          </div>
        </div>
      ) : (
        <div className="bg-white border border-slate-200 rounded-lg overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-slate-50 border-b border-slate-200">
              <tr className="text-left text-slate-600">
                <th className="px-4 py-2 font-semibold">Name</th>
                <th className="px-4 py-2 font-semibold">Status</th>
                <th className="px-4 py-2 font-semibold">Policy</th>
                <th className="px-4 py-2 font-semibold text-right">Tiles</th>
                <th className="px-4 py-2 font-semibold text-right">Vendors</th>
                <th className="px-4 py-2 font-semibold text-right">Estimate</th>
                <th className="px-4 py-2 font-semibold text-right">Spent</th>
                <th className="px-4 py-2 font-semibold text-right">Cap</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data.campaigns.map((c) => (
                <tr key={c.id} className="hover:bg-slate-50">
                  <td className="px-4 py-3">
                    <Link to={`/admin/carafe/campaigns/${c.id}`} className="font-semibold text-slate-900 hover:text-violet-700">
                      {c.name}
                    </Link>
                    <div className="text-[11px] text-slate-500">{c.density_profile}</div>
                  </td>
                  <td className="px-4 py-3"><StatusBadge status={c.status} /></td>
                  <td className="px-4 py-3 text-slate-600">{c.enrich_policy}</td>
                  <td className="px-4 py-3 text-right tabular-nums">
                    {c.tiles_done_count}/{c.tile_count}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">{c.vendor_count}</td>
                  <td className="px-4 py-3 text-right tabular-nums text-slate-600">
                    {c.estimate_expected_usd != null ? `$${(+c.estimate_expected_usd).toFixed(2)}` : '—'}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums font-semibold">
                    ${(+c.spent_usd).toFixed(2)}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums text-slate-500">
                    {c.budget_cap_usd != null ? `$${(+c.budget_cap_usd).toFixed(0)}` : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

function StatusBadge({ status }: { status: string }) {
  const palette: Record<string, string> = {
    draft:      'bg-slate-100 text-slate-700',
    estimating: 'bg-blue-50 text-blue-700',
    approved:   'bg-blue-50 text-blue-700',
    running:    'bg-emerald-50 text-emerald-700',
    paused:     'bg-amber-50 text-amber-700',
    done:       'bg-emerald-100 text-emerald-800',
    failed:     'bg-red-50 text-red-700',
    cancelled:  'bg-slate-100 text-slate-500',
  };
  return (
    <span className={`inline-block text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded ${palette[status] ?? palette.draft}`}>
      {status}
    </span>
  );
}
