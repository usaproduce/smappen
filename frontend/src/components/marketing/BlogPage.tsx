import { useEffect } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';

/**
 * Blog index at /blog. Posts are static for now — each is a small TSX
 * component imported here. The content is SEO content targeting
 * "franchise territory mapping", "drive time analysis tool",
 * "trade area analysis software", "competitor mapping". Each post links
 * to a relevant in-app feature with a signup CTA.
 *
 * v1 ships the index + 3 cornerstone post stubs. Real posts come later.
 */

const POSTS = [
  {
    slug: 'how-to-do-trade-area-analysis',
    date: '2026-05-20',
    title: 'How to do a trade area analysis without spending $10K on consultants',
    excerpt: 'The 4-step process site-selection consultants charge $10K for, broken down so you can do it yourself in 30 minutes.',
    minutes: 7,
  },
  {
    slug: 'drive-time-vs-radius',
    date: '2026-05-12',
    title: 'Drive-time areas vs. radius circles: which one should you use?',
    excerpt: 'Radii are easy. Isochrones are accurate. Here\'s when each one matters and how to choose.',
    minutes: 5,
  },
  {
    slug: 'franchise-territory-balancing',
    date: '2026-05-03',
    title: 'Balancing franchise territories without making your franchisees mad',
    excerpt: 'The math (and the politics) of carving a region into balanced sales territories.',
    minutes: 9,
  },
];

export default function BlogPage() {
  useEffect(() => {
    document.title = 'Blog — Smappen';
    const meta = document.querySelector('meta[name="description"]') as HTMLMetaElement | null;
    if (meta) meta.content = 'Guides on franchise territory mapping, drive-time analysis, site selection, and competitive intelligence.';
  }, []);

  return (
    <div className="min-h-screen bg-white">
      <header className="border-b border-slate-100">
        <div className="max-w-3xl mx-auto px-6 py-4 flex items-center justify-between">
          <Link to="/" className="flex items-center gap-2 font-extrabold text-[16px]" style={{ color: '#1A1A2E' }}>
            <span
              className="inline-flex items-center justify-center w-8 h-8 rounded-lg text-white font-extrabold text-base shadow-sm"
              style={{ background: 'linear-gradient(135deg, #F57C00 0%, #E53935 50%, #7848BB 100%)' }}
            >S</span>
            smappen
          </Link>
          <Link to="/" className="text-xs text-slate-500 hover:text-violet-700 inline-flex items-center gap-1">
            <ArrowLeft size={12} /> Home
          </Link>
        </div>
      </header>
      <main className="max-w-3xl mx-auto px-6 py-12">
        <h1 className="text-3xl font-extrabold" style={{ color: '#1A1A2E' }}>Smappen blog</h1>
        <p className="text-slate-600 mt-2">Guides for territory planners, site selectors, and franchise ops.</p>
        <ul className="mt-10 space-y-8">
          {POSTS.map((p) => (
            <li key={p.slug} className="border-b border-slate-100 pb-6">
              <div className="text-[11px] uppercase tracking-wider font-bold text-slate-400">{p.date} · {p.minutes}-min read</div>
              <h2 className="text-xl font-extrabold mt-1" style={{ color: '#1A1A2E' }}>
                <Link to={`/blog/${p.slug}`} className="hover:text-violet-700">{p.title}</Link>
              </h2>
              <p className="text-sm text-slate-600 mt-2 leading-relaxed">{p.excerpt}</p>
              <Link to={`/blog/${p.slug}`} className="text-sm font-semibold text-violet-700 hover:underline mt-2 inline-block">
                Read →
              </Link>
            </li>
          ))}
        </ul>
      </main>
    </div>
  );
}
