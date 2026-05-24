import { Link } from 'react-router-dom';
import { Sparkles, ArrowLeft } from 'lucide-react';

/**
 * Public-facing changelog at `/changelog`. Same release entries that power
 * the WhatsNewModal, exposed publicly for SEO + customer trust. Each entry
 * is a flat object below — adding a new release means prepending one block.
 *
 * Indexable by search engines; we don't gate it behind auth.
 */
interface ReleaseEntry {
  date: string;
  title: string;
  items: string[];
}

const RELEASES: ReleaseEntry[] = [
  {
    date: '2026-05-24',
    title: 'Big release: 25 visuals, 25 ops, 5 features, 10 fixes',
    items: [
      'Nationwide census coverage — all 50 states + DC, ~84,000 tracts',
      'Analog Finder: pick your best location, find every match by demographic + competitive fingerprint',
      'Drive-time matrix, sales territory rebalancer, 3D extrusion view',
      'Global command palette — open with Ctrl + /',
      'Smooth fly-to map transitions + animated stat counters',
      'Saved-view URLs — every viewport now has a shareable link',
      'Drag-reorder areas now persists across reloads',
      'Heatmap viewport bug fixed for western states',
    ],
  },
  {
    date: '2026-05-23',
    title: 'Time machine + cost tracking',
    items: [
      'Daypart view — animate drive-time across a 24-hour day to see how traffic shrinks your reach',
      'Live Google API cost widget in the header',
      'Heatmap palette browser with seven curated color schemes',
      'Better friendly errors when a Google API isn\'t enabled',
    ],
  },
];

export default function ChangelogPage() {
  return (
    <div className="min-h-screen" style={{ background: '#F9F9FB' }}>
      <header className="bg-white border-b border-slate-200">
        <div className="max-w-3xl mx-auto px-6 py-4 flex items-center justify-between">
          <Link to="/" className="flex items-center gap-2 font-extrabold text-[16px] tracking-tight" style={{ color: '#1A1A2E' }}>
            <span
              className="inline-flex items-center justify-center w-8 h-8 rounded-lg text-white font-extrabold text-base shadow-sm"
              style={{ background: 'linear-gradient(135deg, #F57C00 0%, #E53935 50%, #7848BB 100%)' }}
            >
              S
            </span>
            smappen
          </Link>
          <Link to="/" className="text-xs text-slate-500 hover:text-violet-700 inline-flex items-center gap-1">
            <ArrowLeft size={12} /> Back to app
          </Link>
        </div>
      </header>

      <main className="max-w-3xl mx-auto px-6 py-10">
        <div className="flex items-center gap-2 text-violet-700 font-bold text-sm mb-2">
          <Sparkles size={14} /> Changelog
        </div>
        <h1 className="text-3xl font-extrabold mb-2" style={{ color: '#1A1A2E' }}>What's new in Smappen</h1>
        <p className="text-slate-600 mb-10 leading-relaxed">
          Every release we ship. Notable features, fixes, and capacity changes — newest first.
        </p>

        <ul className="space-y-10">
          {RELEASES.map((r) => (
            <li key={r.date} className="border-l-4 border-violet-200 pl-5 relative">
              <span className="absolute -left-2 top-0 w-3 h-3 rounded-full bg-violet-500 ring-4 ring-violet-100" aria-hidden="true" />
              <div className="text-[10px] uppercase tracking-wider font-bold text-violet-700 mb-1">{r.date}</div>
              <h2 className="text-xl font-extrabold mb-3" style={{ color: '#1A1A2E' }}>{r.title}</h2>
              <ul className="space-y-1.5">
                {r.items.map((item) => (
                  <li key={item} className="flex gap-2 text-sm text-slate-700 leading-snug">
                    <span className="text-violet-500 font-bold shrink-0">›</span>
                    <span>{item}</span>
                  </li>
                ))}
              </ul>
            </li>
          ))}
        </ul>

        <div className="mt-16 pt-8 border-t border-slate-200 text-xs text-slate-500 text-center">
          Want every change in your inbox? <Link to="/settings/profile" className="text-violet-700 hover:underline font-semibold">Enable release notifications</Link>.
        </div>
      </main>
    </div>
  );
}
