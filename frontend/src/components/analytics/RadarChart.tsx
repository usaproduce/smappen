interface RadarSeries {
  label: string;
  color: string;
  /** Already-normalized 0..1 values, one per axis. */
  values: number[];
}

interface Props {
  axes: string[];
  series: RadarSeries[];
  size?: number;
}

/**
 * Pure-SVG radar chart. Used to visually compare 2-5 areas across the same
 * demographic axes — much easier to read than the table for "which area
 * is best on what" questions.
 *
 * Caller is responsible for normalizing values to 0..1 (typically by
 * dividing each metric by the max across the comparison set).
 */
export default function RadarChart({ axes, series, size = 280 }: Props) {
  const cx = size / 2;
  const cy = size / 2;
  const r = (size / 2) - 24;
  const n = axes.length;
  if (n < 3) return null;

  function point(idx: number, value: number): { x: number; y: number } {
    const angle = (Math.PI * 2 * idx) / n - Math.PI / 2;
    return {
      x: cx + r * value * Math.cos(angle),
      y: cy + r * value * Math.sin(angle),
    };
  }

  // Ring + spoke skeleton at 25/50/75/100%
  const rings = [0.25, 0.5, 0.75, 1];

  return (
    <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
      {/* Concentric rings */}
      {rings.map((v, i) => (
        <polygon
          key={i}
          points={axes.map((_, ai) => {
            const p = point(ai, v);
            return `${p.x},${p.y}`;
          }).join(' ')}
          fill="none"
          stroke="#E5E7EB"
          strokeWidth={1}
        />
      ))}
      {/* Spokes */}
      {axes.map((_, ai) => {
        const p = point(ai, 1);
        return <line key={ai} x1={cx} y1={cy} x2={p.x} y2={p.y} stroke="#E5E7EB" strokeWidth={1} />;
      })}
      {/* Series polygons */}
      {series.map((s, si) => {
        const pts = s.values.map((v, i) => point(i, Math.max(0, Math.min(1, v))));
        return (
          <g key={si}>
            <polygon
              points={pts.map((p) => `${p.x},${p.y}`).join(' ')}
              fill={s.color}
              fillOpacity={0.18}
              stroke={s.color}
              strokeWidth={2}
            />
            {pts.map((p, i) => (
              <circle key={i} cx={p.x} cy={p.y} r={3} fill={s.color} stroke="#fff" strokeWidth={1.5} />
            ))}
          </g>
        );
      })}
      {/* Axis labels */}
      {axes.map((label, ai) => {
        const p = point(ai, 1.12);
        return (
          <text
            key={ai}
            x={p.x}
            y={p.y}
            textAnchor="middle"
            dominantBaseline="middle"
            fontSize={10}
            fontWeight={600}
            fill="#475569"
          >
            {label}
          </text>
        );
      })}
    </svg>
  );
}
