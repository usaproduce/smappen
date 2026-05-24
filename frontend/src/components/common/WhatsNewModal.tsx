import { useEffect, useState } from 'react';
import { X, Sparkles } from 'lucide-react';
import { createPortal } from 'react-dom';

/**
 * OP19 — once-per-deploy "what's new" modal. Bumps the `LATEST_RELEASE`
 * constant whenever there's a notable change; users see it once, then
 * we stamp their localStorage so it never re-shows for the same version.
 *
 * To add a new release: bump `LATEST_RELEASE`, add a `RELEASES[<key>]`
 * entry. That's it.
 */
const LATEST_RELEASE = '2026.05.24-omnibus';

const RELEASES: Record<string, { title: string; items: string[] }> = {
  '2026.05.24-omnibus': {
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
};

export default function WhatsNewModal() {
  const [open, setOpen] = useState(false);
  useEffect(() => {
    const seen = localStorage.getItem('smappen_last_release_seen');
    if (seen !== LATEST_RELEASE) setOpen(true);
  }, []);
  if (!open) return null;
  const release = RELEASES[LATEST_RELEASE];
  if (!release) return null;
  const close = () => {
    localStorage.setItem('smappen_last_release_seen', LATEST_RELEASE);
    setOpen(false);
  };
  return createPortal(
    <div className="fixed inset-0 bg-black/30 backdrop-blur-sm z-[200] flex items-center justify-center" onClick={close}>
      <div
        className="bg-white rounded-xl shadow-2xl border border-slate-200 w-[min(520px,90vw)] overflow-hidden card-expand"
        onClick={(e) => e.stopPropagation()}
      >
        <div
          className="px-5 py-3 flex items-center justify-between"
          style={{ background: 'linear-gradient(135deg, #F57C00 0%, #E53935 50%, #7848BB 100%)' }}
        >
          <div className="flex items-center gap-2 text-white font-bold">
            <Sparkles size={16} /> What's new
          </div>
          <button onClick={close} className="text-white/80 hover:text-white">
            <X size={16} />
          </button>
        </div>
        <div className="p-5">
          <div className="font-extrabold text-lg mb-3" style={{ color: '#1A1A2E' }}>{release.title}</div>
          <ul className="space-y-1.5">
            {release.items.map((s) => (
              <li key={s} className="flex gap-2 text-sm text-slate-700">
                <span className="text-violet-500 font-bold">›</span>
                <span>{s}</span>
              </li>
            ))}
          </ul>
        </div>
        <div className="px-5 py-3 border-t border-slate-100 flex items-center justify-end">
          <button onClick={close} className="btn btn-primary h-8 text-xs px-4">Got it</button>
        </div>
      </div>
    </div>,
    document.body
  );
}
