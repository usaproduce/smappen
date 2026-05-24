/**
 * Tiny "Made with Smappen — try free →" badge for public share + embed
 * pages. Anchored bottom-right by default; the embed builder can disable
 * it via `show_branding=false` on a paid plan.
 *
 * Intentionally small + neutral so it doesn't dominate the embedded viz;
 * the design goal is a quiet pointer back to the marketing site, not a
 * full call-to-action banner.
 */
export default function SmappenBadge({ visible = true }: { visible?: boolean }) {
  if (!visible) return null;
  return (
    <a
      href="/?ref=embed"
      target="_top"
      className="fixed bottom-3 right-3 z-50 inline-flex items-center gap-1.5 bg-white/95 backdrop-blur border border-slate-200 rounded-full px-2.5 py-1 text-[11px] font-semibold shadow-sm hover:shadow-md transition-shadow"
      style={{ color: '#1A1A2E', textDecoration: 'none' }}
    >
      <span
        className="inline-flex items-center justify-center w-4 h-4 rounded-full text-white font-extrabold"
        style={{ background: 'linear-gradient(135deg, #F57C00 0%, #E53935 50%, #7848BB 100%)', fontSize: '8px' }}
      >S</span>
      <span>Made with Smappen</span>
      <span className="text-violet-600">→</span>
    </a>
  );
}
