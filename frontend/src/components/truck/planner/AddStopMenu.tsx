import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { CalendarDays, Plus, Search, Utensils } from 'lucide-react';
import type { Spot } from '../../../api/truck';
import { feeText, spotMatches } from '../../../utils/truck/spotSummary';
import { Modal } from '../ui';

export interface AddStopMenuProps {
  open: boolean;
  onOpen: () => void;
  onClose: () => void;
  /** The saved spots the owner can add (deleted ones left out), in list order. */
  spots: readonly Spot[];
  /** Ids of the spots that already are a stop of the day. */
  inDay: ReadonlySet<string>;
  /** Why no stop can be added ("A day holds at most 8 stops."), or null. */
  limitText: string | null;
  /** The page's empty state has its own button: leave this one out. */
  hideButton?: boolean;
  onAddSpot: (spot: Spot) => void;
  onAddEvent: () => void;
  onAddCatering: () => void;
}

/** From this many spots on the list gets a search field. */
const SEARCH_FROM = 9;

/**
 * "Add stop" (docs/truck-planner/05_FRONTEND.md 4.5): a saved spot from a list that can be
 * searched, an event, or a catering job. A new spot stop gets its window from the planner (the
 * spot's best three hours of the date that are still free) and lands where that window falls in
 * the day.
 */
export default function AddStopMenu(props: AddStopMenuProps) {
  const { open, onOpen, onClose, spots, inDay, limitText, hideButton, onAddSpot, onAddEvent, onAddCatering } = props;
  const [search, setSearch] = useState('');

  // Every opening starts with the whole list.
  useEffect(() => {
    if (open) setSearch('');
  }, [open]);

  const shown = spots.filter((spot) => spotMatches(spot, search));
  const full = limitText !== null;

  return (
    <>
      {hideButton === true ? null : (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
          <button
            type="button"
            className="btn btn-secondary h-11 md:h-9 px-3 text-sm disabled:opacity-50 disabled:cursor-not-allowed"
            data-tp-add-stop=""
            disabled={full}
            onClick={onOpen}
          >
            <Plus size={15} aria-hidden /> Add stop
          </button>
          {full ? (
            <span className="text-[13px] font-semibold" style={{ color: 'var(--body)' }}>
              {limitText}
            </span>
          ) : null}
        </div>
      )}

      <Modal open={open && !full} onClose={onClose} title="Add a stop" size="sm">
        <h3 className="label">A saved spot</h3>
        {spots.length >= SEARCH_FROM ? (
          <div className="relative mb-2">
            <Search size={15} aria-hidden className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--slate)' }} />
            <input
              type="search"
              className="input h-11 md:h-9 text-sm font-semibold"
              style={{ paddingLeft: 34 }}
              placeholder="Search your spots"
              aria-label="Search your spots"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
        ) : null}
        {spots.length === 0 ? (
          <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
            You have no saved spots yet.{' '}
            <Link to="/truck/map" className="font-bold underline underline-offset-2" style={{ color: 'var(--ink)' }}>
              Open the map
            </Link>
          </p>
        ) : shown.length === 0 ? (
          <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
            No spot matches "{search.trim()}".
          </p>
        ) : (
          <ul className="tp-stat-list">
            {shown.map((spot) => (
              <li key={spot.id}>
                <button
                  type="button"
                  className="tp-row-click flex min-h-[52px] w-full items-center gap-3 rounded-lg px-2 py-2 text-left"
                  onClick={() => onAddSpot(spot)}
                >
                  <span className="min-w-0 flex-1">
                    <span className="block text-sm font-bold" style={{ color: 'var(--ink)' }}>
                      {spot.name}
                    </span>
                    <span className="block text-xs font-semibold" style={{ color: 'var(--body)' }}>
                      {spot.address !== '' ? spot.address : feeText(spot.terms)}
                    </span>
                  </span>
                  {inDay.has(spot.id) ? <span className="tp-chip tp-chip-sm flex-none">In this day</span> : null}
                  <Plus size={16} aria-hidden className="flex-none" style={{ color: 'var(--slate)' }} />
                </button>
              </li>
            ))}
          </ul>
        )}

        <h3 className="label mt-4">Another kind of stop</h3>
        <div className="grid grid-cols-2 gap-2">
          <button type="button" className="btn btn-secondary min-h-[44px] px-3 text-sm" onClick={onAddEvent}>
            <CalendarDays size={15} aria-hidden /> Event
          </button>
          <button type="button" className="btn btn-secondary min-h-[44px] px-3 text-sm" onClick={onAddCatering}>
            <Utensils size={15} aria-hidden /> Catering job
          </button>
        </div>
      </Modal>
    </>
  );
}
