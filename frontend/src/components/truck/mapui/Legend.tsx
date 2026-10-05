import { forwardRef, useImperativeHandle, useLayoutEffect, useRef } from 'react';
import { ChevronDown, ChevronUp } from 'lucide-react';
import type { RegionInfo } from '../../../api/truck';
import type { MapLayer } from '../../../utils/truck/model';
import { LEGEND_CAPTIONS, LEGEND_TITLES, markerShare } from '../../../utils/truck/hourControl';
import { FLOOR_TEXT, LEGEND_SWATCHES, legendSwatches, legendTicks } from '../../../utils/truck/palette';
import { SourceLine } from '../ui';
import { useThemeName } from '../ui/useThemeName';

export interface LegendProps {
  layer: MapLayer;
  /** The vintages of the region's data, for the source line; null without a region. */
  vintages: RegionInfo['vintages'];
  /** False: the legend is the "Legend" button with the source line beside it. */
  open: boolean;
  onToggle: () => void;
}

export interface LegendHandle {
  /** Moves the marker to the hovered cell's colour byte; null or 0 (no colour) hides it. */
  setMarker(byte: number | null): void;
}

/** The ramp is drawn in a view box this wide: one unit is one pixel in the 280 px card. */
const RAMP_WIDTH = 256;
const SWATCH_WIDTH = RAMP_WIDTH / LEGEND_SWATCHES;

/**
 * The legend of the map colours (docs/truck-planner/05_FRONTEND.md 4.2): a title by layer, a ramp
 * of 32 flat swatches taken from the layer's colour table (never a CSS gradient), ticks at fixed
 * values, the floor under which a cell stays uncoloured, a caption, and the source line.
 *
 * The scale is fixed for the whole week (5.5), so the ticks never move. The marker shows where the
 * hovered cell sits on the ramp; the map page moves it through the handle, without a React render.
 * The source line stays on screen when the legend is closed: the colours are partly computed from
 * OpenStreetMap data.
 */
const Legend = forwardRef<LegendHandle, LegendProps>(function Legend({ layer, vintages, open, onToggle }, ref) {
  const theme = useThemeName();
  const marker = useRef<SVGGElement>(null);
  const byte = useRef<number | null>(null);

  const place = () => {
    const el = marker.current;
    if (el === null) return;
    const share = markerShare(byte.current);
    if (share === null) {
      el.style.display = 'none';
      return;
    }
    el.setAttribute('transform', 'translate(' + String(share * RAMP_WIDTH) + ' 0)');
    el.style.display = '';
  };

  useImperativeHandle(
    ref,
    () => ({
      setMarker: (next) => {
        byte.current = next;
        place();
      },
    }),
    [],
  );

  // The marker element comes and goes with the open legend: put it where the last hover left it.
  useLayoutEffect(place);

  if (!open) {
    return (
      <div className="bg-white rounded-xl border shadow-float flex items-center gap-2 p-1 pr-2.5" style={{ borderColor: 'var(--line-soft)' }}>
        <button
          type="button"
          onClick={onToggle}
          aria-expanded={false}
          className="inline-flex h-11 md:h-9 flex-none items-center gap-1 rounded-lg px-2.5 text-[13px] font-bold"
          style={{ color: 'var(--ink)', background: 'var(--bg-panel)' }}
        >
          Legend
          <ChevronDown size={14} aria-hidden />
        </button>
        <div className="min-w-0 [&>p]:text-[11px] [&>p]:leading-[1.25]">
          <SourceLine kinds={['map']} vintages={vintages} />
        </div>
      </div>
    );
  }

  const swatches = legendSwatches(layer, theme);
  const ticks = legendTicks(layer);
  const title = LEGEND_TITLES[layer];

  return (
    <section
      aria-label="Legend"
      className="bg-white rounded-xl border shadow-float min-h-0 overflow-y-auto p-3"
      style={{ borderColor: 'var(--line-soft)' }}
    >
      <div className="flex items-start justify-between gap-2">
        <h2 className="text-sm font-extrabold leading-snug" style={{ color: 'var(--ink)' }}>
          {title}
        </h2>
        <button
          type="button"
          onClick={onToggle}
          aria-expanded
          aria-label="Hide legend"
          className="-mr-1.5 -mt-1.5 inline-flex h-11 w-11 md:h-8 md:w-8 flex-none items-center justify-center rounded-lg"
          style={{ color: 'var(--body)' }}
        >
          <ChevronUp size={16} aria-hidden />
        </button>
      </div>

      <svg
        viewBox={'0 0 ' + String(RAMP_WIDTH) + ' 38'}
        width="100%"
        role="img"
        aria-label={
          'Colour scale for ' + title + ', from ' + ticks[0].label + ' to ' + ticks[ticks.length - 1].label + '. ' +
          (theme === 'dark' ? 'Lighter means more.' : 'Darker means more.')
        }
        className="mt-1.5 block"
        style={{ maxWidth: RAMP_WIDTH, overflow: 'visible' }}
      >
        <g shapeRendering="crispEdges">
          {swatches.map((color, i) => (
            <rect key={i} x={i * SWATCH_WIDTH} y={8} width={SWATCH_WIDTH} height={12} fill={color} />
          ))}
        </g>
        <rect x={0.5} y={8.5} width={RAMP_WIDTH - 1} height={11} fill="none" stroke="var(--line)" strokeWidth={1} />
        {ticks.map((tick, i) => {
          const x = tick.position * RAMP_WIDTH;
          const last = i === ticks.length - 1;
          return (
            <g key={tick.value} aria-hidden>
              <rect x={last ? RAMP_WIDTH - 1 : x - 0.5} y={20} width={1} height={4} fill="var(--slate)" />
              <text x={last ? RAMP_WIDTH : x} y={35} textAnchor={last ? 'end' : 'middle'} fontSize="11" fontWeight={700} fill="var(--slate)">
                {tick.label}
              </text>
            </g>
          );
        })}
        <g ref={marker} aria-hidden>
          <path d="M -4.5 0 L 4.5 0 L 0 6.5 Z" fill="var(--ink)" />
          <rect x={-1} y={6} width={2} height={15} fill="var(--ink)" />
        </g>
      </svg>

      <p className="mt-1.5 text-xs font-semibold" style={{ color: 'var(--body)' }}>
        No colour: under {FLOOR_TEXT[layer]}
      </p>
      <p className="mt-1.5 text-xs font-medium leading-snug" style={{ color: 'var(--body)' }}>
        {LEGEND_CAPTIONS[layer]}
      </p>
      <div className="mt-2 border-t pt-2" style={{ borderColor: 'var(--line-soft)' }}>
        <SourceLine kinds={['map']} vintages={vintages} />
      </div>
    </section>
  );
});

export default Legend;
