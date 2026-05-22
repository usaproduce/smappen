import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Check, MapPin } from 'lucide-react';
import toast from 'react-hot-toast';
import { useAuthStore } from '../../stores/authStore';
import { billingApi } from '../../api/billing';

interface Tier {
  id: 'free' | 'starter' | 'pro' | 'business';
  name: string;
  monthly: number;
  annual: number;
  features: string[];
  popular?: boolean;
  cta: string;
}

const TIERS: Tier[] = [
  { id: 'free', name: 'Free', monthly: 0, annual: 0,
    features: ['1 project', '3 areas', '5 isochrones/day', 'Basic demographics'], cta: 'Get Started' },
  { id: 'starter', name: 'Starter', monthly: 49, annual: 39,
    features: ['5 projects', '25 areas', '50 isochrones/day', 'Full demographics', '50 POI searches/day', 'PDF reports', 'CSV/Excel export'], cta: 'Start Trial' },
  { id: 'pro', name: 'Pro', monthly: 149, annual: 119, popular: true,
    features: ['Unlimited projects', 'Unlimited areas', 'Unlimited isochrones', 'Unlimited POI searches', 'Import up to 500 rows', 'Everything in Starter'], cta: 'Start Trial' },
  { id: 'business', name: 'Business', monthly: 349, annual: 279,
    features: ['Everything in Pro', '10 team seats', 'API access', 'Import up to 2,000 rows', 'Priority support'], cta: 'Start Trial' },
];

export default function PricingPage() {
  const [annual, setAnnual] = useState(false);
  const { user } = useAuthStore();

  async function upgrade(plan: 'starter' | 'pro' | 'business') {
    if (!user) { location.href = '/register'; return; }
    try {
      const r = await billingApi.createCheckout(plan);
      location.href = r.checkout_url;
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Checkout failed');
    }
  }

  return (
    <div className="min-h-screen">
      <header className="bg-white border-b border-slate-200 px-4 h-14 flex items-center">
        <Link to="/" className="flex items-center gap-2 font-bold" style={{ color: '#1e3a5f' }}>
          <MapPin size={20} color="var(--brand)" /> Smappen
        </Link>
      </header>
      <main className="max-w-6xl mx-auto py-12 px-4">
        <h1 className="text-4xl font-bold text-center" style={{ color: '#1e3a5f' }}>Choose your plan</h1>
        <p className="text-center text-slate-500 mt-2">Mapping territories for teams of every size.</p>

        <div className="flex justify-center gap-2 mt-6">
          <button className={`btn ${!annual ? 'btn-primary' : 'btn-secondary'}`} onClick={() => setAnnual(false)}>Monthly</button>
          <button className={`btn ${annual ? 'btn-primary' : 'btn-secondary'}`} onClick={() => setAnnual(true)}>Annual (-20%)</button>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mt-10">
          {TIERS.map((t) => (
            <div key={t.id} className={`card flex flex-col ${t.popular ? 'ring-2 ring-violet-600 relative' : ''}`}>
              {t.popular && (
                <div className="absolute -top-3 left-1/2 -translate-x-1/2 bg-violet-600 text-white text-xs font-semibold px-3 py-1 rounded-full">
                  Most popular
                </div>
              )}
              <div className="font-bold text-lg">{t.name}</div>
              <div className="text-3xl font-bold mt-1" style={{ color: '#1e3a5f' }}>
                ${annual ? t.annual : t.monthly}<span className="text-sm text-slate-500 font-normal">/mo</span>
              </div>
              {annual && t.monthly > 0 && <div className="text-xs text-slate-500">billed annually</div>}
              <ul className="mt-4 space-y-2 text-sm flex-1">
                {t.features.map((f) => (
                  <li key={f} className="flex gap-2"><Check size={16} className="text-emerald-600 shrink-0 mt-0.5" /> {f}</li>
                ))}
              </ul>
              <button className={`btn ${t.popular ? 'btn-primary' : 'btn-secondary'} justify-center mt-6`}
                onClick={() => t.id === 'free' ? location.href = '/register' : upgrade(t.id as any)}>
                {user?.plan === t.id ? 'Current plan' : t.cta}
              </button>
            </div>
          ))}
        </div>
      </main>
    </div>
  );
}
