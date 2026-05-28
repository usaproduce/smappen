import { type ReactNode } from 'react';

/**
 * Layout-shaped skeletons for Carafe surfaces.
 *
 * The principle: a loading state should look like the real page paused
 * mid-render, not a row of empty gray pills. Every block here mirrors
 * the silhouette of the component it stands in for. The shimmer class
 * (.skeleton) already supports dark mode and prefers-reduced-motion
 * (the latter via styles.css), so individual helpers don't need to
 * re-declare either.
 */

export function SkeletonBlock({
  className = '',
  style,
  children,
}: {
  className?: string;
  style?: React.CSSProperties;
  children?: ReactNode;
}) {
  return (
    <div className={`skeleton ${className}`} style={style} aria-hidden>
      {children}
    </div>
  );
}

/** Card silhouette — the most common per-page wrapper. */
export function SkeletonCard({ className = '', minH = 80 }: { className?: string; minH?: number }) {
  return (
    <div
      className={'rounded-xl border p-4 flex flex-col gap-3 ' + className}
      style={{ background: 'white', borderColor: 'var(--line-soft)', minHeight: minH }}
      aria-hidden
    >
      <SkeletonBlock className="h-3 w-24" />
      <SkeletonBlock className="h-8 w-1/2" />
      <SkeletonBlock className="h-3 w-3/4" />
    </div>
  );
}

/** Stat row with three side-by-side tiles (food cost %, revenue, units…). */
export function SkeletonStatRow({ count = 3 }: { count?: number }) {
  return (
    <div className="grid gap-3" style={{ gridTemplateColumns: `repeat(${count}, minmax(0, 1fr))` }}>
      {Array.from({ length: count }, (_, i) => (
        <div
          key={i}
          className="rounded-lg border p-3 flex flex-col gap-2"
          style={{ background: 'white', borderColor: 'var(--line-soft)' }}
          aria-hidden
        >
          <SkeletonBlock className="h-2.5 w-16" />
          <SkeletonBlock className="h-6 w-24" />
        </div>
      ))}
    </div>
  );
}

/** Vertical list of identical rows — recipe sidebar, vendor saved, goals. */
export function SkeletonList({ rows = 4, rowHeight = 56 }: { rows?: number; rowHeight?: number }) {
  return (
    <ul className="space-y-2" aria-hidden>
      {Array.from({ length: rows }, (_, i) => (
        <li key={i}>
          <div
            className="rounded-xl border flex items-center gap-3 px-3"
            style={{ background: 'white', borderColor: 'var(--line-soft)', height: rowHeight }}
          >
            <SkeletonBlock className="rounded-md flex-shrink-0" style={{ width: 40, height: 40 }} />
            <div className="flex-1 flex flex-col gap-1.5">
              <SkeletonBlock className="h-3 w-2/3" />
              <SkeletonBlock className="h-2.5 w-1/3" />
            </div>
            <SkeletonBlock className="h-6 w-16" />
          </div>
        </li>
      ))}
    </ul>
  );
}

/** Table silhouette — used by menu items, top-contributors. */
export function SkeletonTable({ rows = 6 }: { rows?: number }) {
  return (
    <div
      className="rounded-xl border overflow-hidden"
      style={{ background: 'white', borderColor: 'var(--line-soft)' }}
      aria-hidden
    >
      <div
        className="h-10 px-3 flex items-center gap-3"
        style={{ background: 'var(--bg-panel)' }}
      >
        <SkeletonBlock className="h-2.5 w-24" />
        <SkeletonBlock className="h-2.5 w-16" />
        <SkeletonBlock className="h-2.5 w-16 ml-auto" />
        <SkeletonBlock className="h-2.5 w-20" />
      </div>
      <ul className="divide-y" style={{ borderColor: 'var(--line-soft)' }}>
        {Array.from({ length: rows }, (_, i) => (
          <li key={i} className="h-12 px-3 flex items-center gap-3"
              style={{ borderTop: i === 0 ? 'none' : '1px solid var(--line-soft)' }}>
            <SkeletonBlock className="h-3 w-1/3" />
            <SkeletonBlock className="h-3 w-16" />
            <SkeletonBlock className="h-3 w-12 ml-auto" />
            <SkeletonBlock className="h-3 w-16" />
          </li>
        ))}
      </ul>
    </div>
  );
}

/** Chart silhouette — squareish block + axis ticks underneath. */
export function SkeletonChart({ height = 320 }: { height?: number }) {
  return (
    <div
      className="rounded-xl border p-3 sm:p-5 flex flex-col gap-3"
      style={{ background: 'white', borderColor: 'var(--line-soft)' }}
      aria-hidden
    >
      <div className="flex items-center justify-between gap-3">
        <div className="flex flex-col gap-1.5">
          <SkeletonBlock className="h-4 w-32" />
          <SkeletonBlock className="h-3 w-64" />
        </div>
        <SkeletonBlock className="h-9 w-32 rounded-md" />
      </div>
      <SkeletonBlock className="rounded-lg" style={{ height }} />
      <div className="flex gap-3">
        {[0, 1, 2, 3].map((i) => <SkeletonBlock key={i} className="h-3 w-20" />)}
      </div>
    </div>
  );
}

/** Recommendation card silhouette — large dollar figure + action row. */
export function SkeletonRecCard() {
  return (
    <div
      className="rounded-xl border-2 p-4 flex flex-col gap-3"
      style={{ background: 'white', borderColor: 'var(--line-soft)' }}
      aria-hidden
    >
      <div className="flex items-start gap-3">
        <SkeletonBlock className="rounded-md flex-shrink-0" style={{ width: 40, height: 40 }} />
        <div className="flex-1 flex flex-col gap-2">
          <SkeletonBlock className="h-2.5 w-20" />
          <SkeletonBlock className="h-9 w-40" />
          <SkeletonBlock className="h-3 w-3/4" />
        </div>
      </div>
      <div className="flex items-center gap-2">
        <SkeletonBlock className="h-11 flex-1 rounded-lg" />
        <SkeletonBlock className="h-11 w-24 rounded-lg" />
        <SkeletonBlock className="h-11 w-24 rounded-lg" />
      </div>
    </div>
  );
}
