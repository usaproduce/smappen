import { MapPinned } from 'lucide-react';

/**
 * STUB (docs/truck-planner/05_FRONTEND.md 4.2 and 9.2). The map page package replaces the body: the
 * map with its layer switch, legend, hour bar and tools, and the spot card that opens on a click.
 *
 * The layout gives this section no page frame: the page fills the box under the sub-nav. The stub
 * brings its own padding.
 */
export default function MapPage() {
  return (
    <div className="absolute inset-0 overflow-y-auto">
      <div className="max-w-7xl mx-auto px-4 md:px-6 py-4 md:py-6 space-y-2">
        <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
          <MapPinned size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> Map
        </h1>
        <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
          Not built yet.
        </p>
      </div>
    </div>
  );
}
