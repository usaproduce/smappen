import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { billingApi } from '../../api/billing';
import { MapPin } from 'lucide-react';

export default function BillingSettings() {
  const { data, isLoading, refetch } = useQuery({
    queryKey: ['subscription'],
    queryFn: () => billingApi.subscription(),
  });

  async function openPortal() {
    try {
      const r = await billingApi.portal();
      location.href = r.portal_url;
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Portal unavailable');
    }
  }

  async function cancel() {
    if (!confirm('Cancel subscription at period end?')) return;
    try {
      await billingApi.cancel();
      toast.success('Subscription will cancel at period end');
      refetch();
    } catch (e: any) {
      toast.error('Cancel failed');
    }
  }

  return (
    <div className="min-h-screen">
      <header className="bg-white border-b border-slate-200 px-4 h-14 flex items-center">
        <Link to="/" className="flex items-center gap-2 font-bold" style={{ color: '#1e3a5f' }}>
          <MapPin size={20} color="var(--brand)" /> Smappen
        </Link>
      </header>
      <main className="max-w-2xl mx-auto py-10 px-4 space-y-4">
        <h1 className="text-2xl font-bold" style={{ color: '#1e3a5f' }}>Billing & Plan</h1>
        {isLoading || !data ? (
          <div className="skeleton h-40" />
        ) : (
          <>
            <div className="card">
              <div className="text-xs uppercase font-semibold text-slate-500">Current plan</div>
              <div className="text-3xl font-bold capitalize" style={{ color: '#1e3a5f' }}>{data.plan}</div>
              {data.subscription?.current_period_end && (
                <div className="text-xs text-slate-500 mt-1">
                  Renews {new Date(data.subscription.current_period_end * 1000).toLocaleDateString()}
                </div>
              )}
            </div>
            <div className="card space-y-2">
              <div className="font-semibold">Usage today</div>
              <div className="flex justify-between text-sm">
                <span>Isochrones remaining</span>
                <b>{data.usage?.isochrones_remaining_today === -1 ? '∞' : data.usage?.isochrones_remaining_today}</b>
              </div>
              <div className="flex justify-between text-sm">
                <span>POI searches remaining</span>
                <b>{data.usage?.poi_searches_remaining_today === -1 ? '∞' : data.usage?.poi_searches_remaining_today}</b>
              </div>
            </div>
            <div className="flex gap-2">
              <Link to="/pricing" className="btn btn-primary">Change plan</Link>
              <button className="btn btn-secondary" onClick={openPortal}>Manage billing</button>
              {data.plan !== 'free' && <button className="btn btn-danger ml-auto" onClick={cancel}>Cancel</button>}
            </div>
          </>
        )}
      </main>
    </div>
  );
}
