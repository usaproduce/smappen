import { useEffect } from 'react';
import { Link } from 'react-router-dom';
import { MapPin, Sparkles, Users, BarChart3, Building2, Check, ArrowRight } from 'lucide-react';
import { useAuthStore } from '../../stores/authStore';

/**
 * Public homepage at `/`. Hero + value props + pricing teaser + footer.
 * Indexable by Google — sets meta/og tags via the `useDocumentMeta` helper.
 *
 * Logged-in users land on the app, not here — `App.tsx` redirects via
 * <ProtectedRoute> on `/*`, so `/` is reachable for logged-out visitors
 * and explicit navigations only.
 */
export default function HomePage() {
  const isAuthed = useAuthStore((s) => !!s.token);
  useEffect(() => {
    document.title = 'Smappen — Territory mapping, demographics, drive-time analysis';
    setMeta('description', 'Map drive-time areas, pull Census demographics for all 50 states, find lookalike locations, and generate territories. Built for franchise + sales + delivery teams.');
    setMeta('og:title', 'Smappen — Territory mapping for franchise + sales');
    setMeta('og:image', 'https://smappen.mygreendock.com/og.png');
  }, []);

  return (
    <div className="min-h-screen bg-white">
      {/* Header */}
      <header className="border-b border-slate-100">
        <div className="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between">
          <Link to="/" className="flex items-center gap-2 font-extrabold text-[17px]" style={{ color: '#1A1A2E' }}>
            <span
              className="inline-flex items-center justify-center w-9 h-9 rounded-lg text-white font-extrabold text-lg shadow-sm"
              style={{ background: 'linear-gradient(135deg, #F57C00 0%, #E53935 50%, #7848BB 100%)' }}
            >S</span>
            smappen
          </Link>
          <nav className="flex items-center gap-5 text-sm font-semibold text-slate-700">
            <Link to="/pricing" className="hover:text-violet-700">Pricing</Link>
            <Link to="/changelog" className="hover:text-violet-700">Changelog</Link>
            <a href="/api/docs" className="hover:text-violet-700">API docs</a>
            {isAuthed ? (
              <Link to="/dashboard" className="btn btn-primary h-9 px-4 text-sm">Open app →</Link>
            ) : (
              <>
                <Link to="/login" className="hover:text-violet-700">Sign in</Link>
                <Link to="/register" className="btn btn-primary h-9 px-4 text-sm">Start free</Link>
              </>
            )}
          </nav>
        </div>
      </header>

      {/* Hero */}
      <section className="px-6 pt-16 pb-12 text-center">
        <div className="max-w-3xl mx-auto">
          <span className="inline-flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-violet-700 bg-violet-50 px-3 py-1 rounded-full mb-4">
            <Sparkles size={11} /> 84,000 census tracts · 50 states · all of Canada coming soon
          </span>
          <h1 className="text-5xl md:text-6xl font-extrabold leading-tight tracking-tight" style={{ color: '#1A1A2E' }}>
            Territory mapping that <span style={{ background: 'linear-gradient(135deg, #F57C00, #7848BB)', WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent' }}>actually answers questions</span>
          </h1>
          <p className="text-lg text-slate-600 mt-5 leading-relaxed max-w-2xl mx-auto">
            Draw drive-time areas, pull real Census demographics, find lookalike locations, generate balanced territories.
            For franchise planners, sales ops, and site-selection teams who are tired of Excel + paper maps.
          </p>
          <div className="mt-7 flex flex-col sm:flex-row gap-3 justify-center">
            <Link to="/register" className="btn btn-primary h-11 px-6 text-base">
              Start free <ArrowRight size={16} />
            </Link>
            <a href="#demo" className="btn btn-secondary h-11 px-6 text-base">
              See a 60-second demo
            </a>
          </div>
          <div className="text-xs text-slate-400 mt-3">No credit card. 3 free areas to start.</div>
        </div>
      </section>

      {/* Demo screenshot placeholder */}
      <section id="demo" className="px-6 py-12">
        <div className="max-w-5xl mx-auto rounded-2xl overflow-hidden border border-slate-200 shadow-2xl">
          {/* In production this is the actual app screenshot or an embedded sample map. */}
          <div className="aspect-[16/9] bg-gradient-to-br from-violet-50 via-white to-amber-50 flex items-center justify-center">
            <div className="text-center">
              <MapPin size={48} className="mx-auto text-violet-500 mb-3" />
              <div className="text-lg font-bold" style={{ color: '#1A1A2E' }}>
                Interactive demo embed
              </div>
              <div className="text-sm text-slate-500 mt-1 max-w-md">
                A live shareable sample project will mount here showing Downtown Chicago with 4 overlapping franchise territories.
              </div>
              <Link to="/register" className="btn btn-primary mt-4 inline-flex">
                Try it with your own address
              </Link>
            </div>
          </div>
        </div>
      </section>

      {/* Value props */}
      <section className="px-6 py-16 bg-slate-50">
        <div className="max-w-5xl mx-auto">
          <h2 className="text-3xl font-extrabold text-center" style={{ color: '#1A1A2E' }}>
            Built for the questions you actually ask
          </h2>
          <div className="grid md:grid-cols-3 gap-6 mt-12">
            <ValueCard
              icon={MapPin}
              title="Drive-time + reach"
              text="Drop a pin, choose a travel time or a target population, and see the polygon that satisfies it. Three travel modes, instant client-side circles, ORS-backed isochrones."
            />
            <ValueCard
              icon={Users}
              title="Real demographics"
              text="ACS 5-year estimates for every Census tract in the country — population, income, age, housing — weighted to your custom polygon. No more rounding to ZIP codes."
            />
            <ValueCard
              icon={Sparkles}
              title="Analog Finder"
              text="Pick your best-performing location and we'll find every census tract with a similar demographic + competitive fingerprint. The thing Buxton charges $50K/yr for."
            />
            <ValueCard
              icon={BarChart3}
              title="Drive-time matrix"
              text="N customers × M stores, full ORS matrix call. See which stores serve which customers in minutes flat — instantly visible as a heatmap."
            />
            <ValueCard
              icon={Building2}
              title="Territory generation"
              text="k-means with equal-population balancing across the entire country. Output is real polygons that follow tract boundaries — not stretched convex hulls."
            />
            <ValueCard
              icon={Sparkles}
              title="Revenue forecasting"
              text="Feed in your existing locations + their revenue. We predict revenue for a candidate site using k-NN in the 18-dim demographic space, with confidence bands."
            />
          </div>
        </div>
      </section>

      {/* Pricing teaser */}
      <section className="px-6 py-16">
        <div className="max-w-4xl mx-auto text-center">
          <h2 className="text-3xl font-extrabold" style={{ color: '#1A1A2E' }}>
            Start free. Upgrade when you need power.
          </h2>
          <p className="text-slate-600 mt-3">No credit card to start. Annual plans get two months free.</p>
          <div className="grid md:grid-cols-4 gap-4 mt-8 text-left">
            {[
              { name: 'Free',     price: '$0',     features: ['Up to 3 areas', 'Demographics', 'Manual export'] },
              { name: 'Starter',  price: '$29',    features: ['Unlimited areas', 'POI search', 'PDF reports'] },
              { name: 'Pro',      price: '$79',    features: ['Analog Finder', 'Territory gen', 'Drive-time matrix'] },
              { name: 'Business', price: '$199',   features: ['Realtime collab', 'CRM integrations', '5 seats'] },
            ].map((p, i) => (
              <div
                key={p.name}
                className={`rounded-xl border p-5 ${i === 2 ? 'border-violet-500 ring-2 ring-violet-200 bg-violet-50' : 'border-slate-200'}`}
              >
                <div className="text-[10px] uppercase font-bold tracking-wider text-violet-700">{p.name}</div>
                <div className="text-3xl font-extrabold mt-1" style={{ color: '#1A1A2E' }}>
                  {p.price}<span className="text-sm text-slate-500 font-medium">/mo</span>
                </div>
                <ul className="mt-3 space-y-1.5 text-sm">
                  {p.features.map((f) => (
                    <li key={f} className="flex gap-2 text-slate-700"><Check size={14} className="text-emerald-600 shrink-0 mt-0.5" />{f}</li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
          <Link to="/pricing" className="btn btn-secondary mt-6 inline-flex">See full comparison</Link>
        </div>
      </section>

      <footer className="border-t border-slate-100 px-6 py-8 text-xs text-slate-500">
        <div className="max-w-6xl mx-auto flex flex-col sm:flex-row justify-between gap-2">
          <span>© Smappen, all rights reserved.</span>
          <div className="flex gap-4">
            <Link to="/pricing" className="hover:text-violet-700">Pricing</Link>
            <Link to="/changelog" className="hover:text-violet-700">Changelog</Link>
            <Link to="/blog" className="hover:text-violet-700">Blog</Link>
            <a href="/api/docs" className="hover:text-violet-700">API</a>
          </div>
        </div>
      </footer>
    </div>
  );
}

function ValueCard({ icon: Icon, title, text }: { icon: any; title: string; text: string }) {
  return (
    <div className="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
      <div className="w-10 h-10 rounded-lg bg-violet-100 flex items-center justify-center text-violet-700 mb-3">
        <Icon size={18} />
      </div>
      <h3 className="font-bold text-base" style={{ color: '#1A1A2E' }}>{title}</h3>
      <p className="text-sm text-slate-600 mt-1 leading-relaxed">{text}</p>
    </div>
  );
}

function setMeta(name: string, content: string) {
  let el = document.querySelector(`meta[name="${name}"], meta[property="${name}"]`) as HTMLMetaElement | null;
  if (!el) {
    el = document.createElement('meta');
    if (name.startsWith('og:')) el.setAttribute('property', name);
    else el.setAttribute('name', name);
    document.head.appendChild(el);
  }
  el.setAttribute('content', content);
}
