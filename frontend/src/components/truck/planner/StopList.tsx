import { Fragment, useEffect, useLayoutEffect, useRef, useState } from 'react';
import type { DragEvent } from 'react';
import type { Spot } from '../../../api/truck';
import type { DraftStop } from '../../../stores/truckPlanDraftStore';
import type { MapsPoint } from '../../../utils/truck/links';
import type { DayStop, LatLng, TimelineStop } from '../../../utils/truck/model';
import type { LegView, StopProblem } from '../../../utils/truck/planDraft';
import type { WarningRow } from '../../../utils/truck/warnings';
import { SkeletonRows } from '../ui';
import LegRow from './LegRow';
import StopCard from './StopCard';

/** One stop of the day with everything its card prints. */
export interface StopListItem {
  stop: DraftStop;
  name: string;
  spot: Spot | null;
  point: LatLng | null;
  problem: StopProblem | null;
  evaluated: DayStop | null;
  timed: TimelineStop | null;
  warningCodes: string[];
  notices: WarningRow[];
  /** Left out where the stop has nothing to open (a catering job is contracted, not estimated). */
  onWhy?: () => void;
}

export interface StopListProps {
  items: readonly StopListItem[];
  /** The drives of the evaluated day: one before each stop and one back to base. Empty while the day is not evaluated. */
  legs: readonly LegView[];
  /** The two ends of a drive, for its link; null when one has no place. */
  legEnds: (leg: LegView) => { origin: MapsPoint; destination: MapsPoint } | null;
  spots: readonly Spot[];
  pending: boolean;
  dim: boolean;
  rebuilding: boolean;
  setupDefault: number;
  teardownDefault: number;
  /** What the polite live region says: "Moved Sterling taproom to position 1 of 2." */
  announcement: string;
  /** A stop to bring into view: the one that was just added. */
  revealId: string | null;
  onPatch: (stopId: string, patch: Partial<DraftStop>) => void;
  onMove: (stopId: string, delta: -1 | 1) => void;
  /** Drop a stop at a position of the list as it stands (0 = first). */
  onMoveTo: (stopId: string, toIndex: number) => void;
  onRemove: (stopId: string) => void;
  onEditLeg: (leg: LegView) => void;
}

/**
 * The day's stops in the owner's order, with the drive before each one and the drive back to base
 * (docs/truck-planner/05_FRONTEND.md 4.5). Nothing here reorders a stop: the two buttons of a card
 * do, on every width and from the keyboard, and so does dragging a card by its handle on a desktop.
 * After a move the button that was pressed keeps focus and a live region says where the stop went.
 */
export default function StopList(props: StopListProps) {
  const { items, legs, legEnds, spots, pending, dim, rebuilding, announcement, revealId } = props;
  const { onPatch, onMove, onMoveTo, onRemove, onEditLeg } = props;
  const cards = useRef(new Map<string, HTMLDivElement>());
  const focusAfter = useRef<{ id: string; control: 'earlier' | 'later' } | null>(null);
  const [armedId, setArmedId] = useState<string | null>(null);
  const [dragId, setDragId] = useState<string | null>(null);
  const [dropAt, setDropAt] = useState<number | null>(null);

  const order = items.map((item) => item.stop.id).join('|');

  // React moves the card that changed place in the document, and a node that is moved loses focus:
  // give it back to the button that was pressed (or to its sibling, at the end of the day).
  useLayoutEffect(() => {
    const want = focusAfter.current;
    if (want === null) return;
    focusAfter.current = null;
    const card = cards.current.get(want.id);
    if (card === undefined) return;
    const pressed = card.querySelector<HTMLButtonElement>('[data-tp-move="' + want.control + '"]:not(:disabled)');
    const other = card.querySelector<HTMLButtonElement>('[data-tp-move]:not(:disabled)');
    const target = pressed !== null ? pressed : other;
    if (target !== null) target.focus();
  }, [order]);

  useEffect(() => {
    if (revealId === null) return;
    const card = cards.current.get(revealId);
    if (card !== undefined && typeof card.scrollIntoView === 'function') card.scrollIntoView({ block: 'nearest' });
  }, [revealId]);

  // A card can be dragged only while its handle is held: a press that ends without a drag lets go
  // of it wherever the pointer is by then (a drag that did start ends with `dragend` instead).
  useEffect(() => {
    if (armedId === null) return undefined;
    const release = () => setArmedId(null);
    window.addEventListener('pointerup', release);
    return () => window.removeEventListener('pointerup', release);
  }, [armedId]);

  const endDrag = () => {
    setArmedId(null);
    setDragId(null);
    setDropAt(null);
  };

  // Where a card would land: the gap before the first card whose middle lies below the pointer.
  // The whole list takes the drop, so letting go over a drive row between two cards works too.
  const onDragOver = (e: DragEvent<HTMLDivElement>) => {
    if (dragId === null) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    let slot = 0;
    for (const item of items) {
      const card = cards.current.get(item.stop.id);
      if (card === undefined) continue;
      const box = card.getBoundingClientRect();
      if (e.clientY > box.top + box.height / 2) slot += 1;
    }
    if (slot !== dropAt) setDropAt(slot);
  };

  const onDrop = (e: DragEvent<HTMLDivElement>) => {
    if (dragId === null) return;
    e.preventDefault();
    const from = items.findIndex((item) => item.stop.id === dragId);
    if (from >= 0 && dropAt !== null) {
      // `dropAt` is a gap between cards; taking the card out first shifts the gaps after it by one.
      const to = dropAt > from ? dropAt - 1 : dropAt;
      if (to !== from) onMoveTo(dragId, to);
    }
    endDrag();
  };

  // Drawn over the gap, so showing it moves nothing.
  const marker = (edge: 'top' | 'bottom') => (
    <div
      aria-hidden
      className={'pointer-events-none absolute inset-x-0 h-1 rounded-full ' + (edge === 'top' ? '-top-1.5' : '-bottom-1.5')}
      style={{ background: 'var(--brand)' }}
    />
  );

  // A drive between two stops at the same place has nothing to correct.
  const legRow = (leg: LegView) => (
    <LegRow
      leg={leg}
      ends={legEnds(leg)}
      dim={dim}
      onEdit={leg.sent !== null && leg.sent.source === 'same_point' ? undefined : () => onEditLeg(leg)}
    />
  );
  const evaluated = legs.length === items.length + 1;

  return (
    <div className="space-y-2" onDragOver={onDragOver} onDrop={onDrop}>
      {items.map((item, index) => {
        const id = item.stop.id;
        return (
          <Fragment key={id}>
            {evaluated ? (
              legRow(legs[index])
            ) : pending && item.problem === null ? (
              <div aria-busy="true">
                <SkeletonRows rows={1} rowHeight={56} />
              </div>
            ) : null}
            <div
              ref={(node) => {
                if (node === null) cards.current.delete(id);
                else cards.current.set(id, node);
              }}
              data-tp-stop={id}
              draggable={armedId === id}
              // Room for the bars that stay on screen when a new card is brought into view.
              className={'relative scroll-mb-28 scroll-mt-28' + (dragId === id ? ' opacity-60' : '')}
              onDragStart={(e) => {
                e.dataTransfer.effectAllowed = 'move';
                // Not text: a card dropped on a field elsewhere must not type into it.
                e.dataTransfer.setData('application/x-tp-stop', id);
                setDragId(id);
              }}
              onDragEnd={endDrag}
            >
              {dragId !== null && dropAt === index ? marker('top') : null}
              {dragId !== null && dropAt === items.length && index === items.length - 1 ? marker('bottom') : null}
              <StopCard
                index={index}
                count={items.length}
                stop={item.stop}
                name={item.name}
                spots={spots}
                spot={item.spot}
                point={item.point}
                problem={item.problem}
                evaluated={item.evaluated}
                timed={item.timed}
                warningCodes={item.warningCodes}
                notices={item.notices}
                pending={pending}
                dim={dim}
                rebuilding={rebuilding}
                setupDefault={props.setupDefault}
                teardownDefault={props.teardownDefault}
                onPatch={(patch) => onPatch(id, patch)}
                onMove={(delta) => {
                  focusAfter.current = { id, control: delta < 0 ? 'earlier' : 'later' };
                  onMove(id, delta);
                }}
                onRemove={() => onRemove(id)}
                onWhy={item.onWhy}
                onGrip={items.length > 1 ? (down) => setArmedId(down ? id : null) : undefined}
              />
            </div>
          </Fragment>
        );
      })}
      {evaluated && items.length > 0 ? legRow(legs[items.length]) : null}
      <div aria-live="polite" role="status" className="sr-only">
        {announcement}
      </div>
    </div>
  );
}
