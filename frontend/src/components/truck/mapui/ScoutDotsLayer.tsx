import { useCallback, useMemo, useRef, useSyncExternalStore } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { DEFAULT_HIDE, dotAriaLabel, dotLabel, readScoutList, uniquePlaces, type ScoutCandidate } from '../../../utils/truck/scoutView';
import { useScout } from '../data';
import MapPin from '../map/MapPin';

export interface ScoutDotsLayerProps {
  /** The "Scout results" toggle of the map tools. */
  enabled: boolean;
  /** A dot was picked: the map opens the spot card at that point. */
  onPick: (p: { lat: number; lng: number; placeKey: string }) => void;
}

const SCOUT_PREFIX = ['truck', 'scout'] as const;
const CENTRE_ON_POINT = { transform: 'translate(-50%, -50%)' } as const;

/**
 * Scout results on the map (docs/truck-planner/05_FRONTEND.md 4.2 and 4.8): one 12 px dot per place
 * of the Scout answers this visit has seen, named on hover and focus. A dot is a real button (44 px
 * on a touch screen, so a finger can hit it); a click opens the estimate at that place and never
 * reaches the map under it.
 *
 * A dot marks a place that could be asked. It says nothing about whether the place takes trucks.
 */
export default function ScoutDotsLayer({ enabled, onPick }: ScoutDotsLayerProps) {
  if (!enabled) return null;
  return <ScoutDots onPick={onPick} />;
}

function ScoutDots({ onPick }: Pick<ScoutDotsLayerProps, 'onPick'>) {
  const qc = useQueryClient();
  // The list as the Scout page asks for it at first. A link that turns the dots on (`?scout=1`)
  // may arrive before any Scout page was open: the list is then asked for here, once.
  useScout(DEFAULT_HIDE);

  // Every Scout answer in the cache: the balanced list, lists with other statuses, one kind in depth.
  const cache = qc.getQueryCache();
  const subscribe = useCallback((onChange: () => void) => cache.subscribe(onChange), [cache]);
  const last = useRef<{ key: string; answers: unknown[] }>({ key: '', answers: [] });
  const answers = useSyncExternalStore(subscribe, () => {
    const queries = cache.findAll({ queryKey: SCOUT_PREFIX }).filter((query) => query.state.data !== undefined);
    // The same answers as last time are the same array: the store snapshot must be stable.
    const key = queries.map((query) => query.queryHash + '@' + String(query.state.dataUpdatedAt)).join('|');
    if (key !== last.current.key) last.current = { key, answers: queries.map((query) => query.state.data) };
    return last.current.answers;
  });
  const places = useMemo(() => uniquePlaces(answers.map(readScoutList)), [answers]);

  return (
    <>
      {places.map((candidate) => (
        <ScoutDot key={candidate.place.place_key} candidate={candidate} onPick={onPick} />
      ))}
    </>
  );
}

function ScoutDot({ candidate, onPick }: { candidate: ScoutCandidate; onPick: ScoutDotsLayerProps['onPick'] }) {
  const { place } = candidate;
  return (
    <MapPin lat={place.lat} lng={place.lng} kind="scout">
      <button
        type="button"
        aria-label={dotAriaLabel(candidate)}
        onClick={(e) => {
          e.stopPropagation();
          onPick({ lat: place.lat, lng: place.lng, placeKey: place.place_key });
        }}
        // 44 px for a finger; smaller for a mouse, so a crowd of dots leaves the map between them clickable.
        className="group absolute flex h-7 w-7 items-center justify-center [@media(pointer:coarse)]:h-11 [@media(pointer:coarse)]:w-11"
        style={CENTRE_ON_POINT}
      >
        <span
          aria-hidden="true"
          className="block h-3 w-3 rounded-full"
          style={{ background: 'var(--accent-revenue)', boxShadow: '0 0 0 2px var(--bg)' }}
        />
        <span
          aria-hidden="true"
          className="bg-white pointer-events-none absolute left-1/2 top-full -mt-3 hidden max-w-[220px] -translate-x-1/2 truncate rounded-full border px-2 py-0.5 text-[11px] font-bold shadow-float group-hover:block group-focus-visible:block"
          style={{ borderColor: 'var(--line-soft)', color: 'var(--ink)' }}
        >
          {dotLabel(candidate)}
        </span>
      </button>
    </MapPin>
  );
}
