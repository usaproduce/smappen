// Layout-shaped loading states (docs/truck-planner/05_FRONTEND.md 3.11), built on the existing
// .skeleton class. The caller wraps them in an element with aria-busy="true"; a whole page never
// just says "Loading".

export interface SkeletonCardProps {
  /** Height of the block in px; default 96. */
  height?: number;
  className?: string;
}

/** One card-sized block. */
export function SkeletonCard({ height = 96, className }: SkeletonCardProps) {
  return <div aria-hidden className={'skeleton rounded-xl' + (className !== undefined ? ' ' + className : '')} style={{ height, borderRadius: 12 }} />;
}

export interface SkeletonRowsProps {
  /** How many rows; default 3. */
  rows?: number;
  /** Height of a row in px; default 72. */
  rowHeight?: number;
  className?: string;
}

/** A list of rows. */
export function SkeletonRows({ rows = 3, rowHeight = 72, className }: SkeletonRowsProps) {
  const items: number[] = [];
  for (let i = 0; i < rows; i++) items.push(i);
  return (
    <div aria-hidden className={'space-y-2' + (className !== undefined ? ' ' + className : '')}>
      {items.map((i) => (
        <div key={i} className="skeleton" style={{ height: rowHeight, borderRadius: 12 }} />
      ))}
    </div>
  );
}

export interface SkeletonChartProps {
  /** Height of the chart area in px; default 140. */
  height?: number;
  className?: string;
}

/** A caption line and a chart-sized block. */
export function SkeletonChart({ height = 140, className }: SkeletonChartProps) {
  return (
    <div aria-hidden className={className}>
      <div className="skeleton mb-2" style={{ height: 12, width: '55%', borderRadius: 4 }} />
      <div className="skeleton" style={{ height, borderRadius: 8 }} />
    </div>
  );
}
