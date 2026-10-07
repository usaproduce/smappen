import { useEffect, useRef, useState } from 'react';
import { Check } from 'lucide-react';
import type { LeadStatus } from '../../../api/truck';
import { LEAD_STATUSES, SCOUT, leadStatusLabel, type ScoutCandidate } from '../../../utils/truck/scoutView';
import { SPOT_LIMITS } from '../../../utils/truck/spotSummary';
import { useSaveLead } from '../data';
import { Field } from '../ui';

export interface LeadControlsProps {
  candidate: ScoutCandidate;
  /** The owner chose another status: the page says so when the place then leaves the list. */
  onStatus?: (status: LeadStatus, previous: LeadStatus) => void;
}

/** How long "Saved" stays under the notes. */
const SAVED_MS = 2500;

/**
 * Where a lead stands and what the owner noted about it (docs/truck-planner/05_FRONTEND.md 4.8,
 * route 39). The status is saved when it is chosen, the notes when the field is left. Both are the
 * owner's own words and are kept for good; nothing here comes from a lookup.
 */
export default function LeadControls({ candidate, onStatus }: LeadControlsProps) {
  const save = useSaveLead();
  const mutate = save.mutate;
  const lead = candidate.lead;
  const placeKey = candidate.place.place_key;
  const uid = 'tp-lead-' + placeKey;
  const saved = lead.notes ?? '';

  const [draft, setDraft] = useState(saved);
  const [noteState, setNoteState] = useState<'idle' | 'saving' | 'saved' | 'failed'>('idle');
  const focused = useRef(false);
  // A save that failed: what was typed stays in the field although the saved note is the old one again.
  const keepTyped = useRef(false);
  const timer = useRef<number | null>(null);

  // A note saved elsewhere (another card of the same place, a refresh) shows here, unless the owner is typing.
  useEffect(() => {
    if (!focused.current && !keepTyped.current) setDraft(saved);
  }, [saved]);

  useEffect(
    () => () => {
      if (timer.current !== null) window.clearTimeout(timer.current);
    },
    [],
  );

  const saveNotes = () => {
    focused.current = false;
    const text = draft.trim();
    if (text === saved.trim()) {
      if (draft !== saved) setDraft(saved);
      return;
    }
    setNoteState('saving');
    mutate(
      { placeKey, patch: { notes: text === '' ? null : text } },
      {
        onSuccess: () => {
          keepTyped.current = false;
          setNoteState('saved');
          if (timer.current !== null) window.clearTimeout(timer.current);
          timer.current = window.setTimeout(() => setNoteState('idle'), SAVED_MS);
        },
        // The hook has put the old note back and shown the server's sentence; what was typed stays in the field.
        onError: () => {
          keepTyped.current = true;
          setNoteState('failed');
        },
      },
    );
  };

  return (
    <div className="space-y-3">
      <Field id={uid + '-status'} label={SCOUT.statusLabel}>
        {(control) => (
          <select
            {...control}
            className="select h-11 md:h-9 text-sm font-bold"
            style={{ paddingTop: 0, paddingBottom: 0 }}
            value={lead.status}
            onChange={(e) => {
              const next = e.target.value as LeadStatus;
              if (next === lead.status) return;
              const previous = lead.status;
              mutate({ placeKey, patch: { status: next } });
              if (onStatus !== undefined) onStatus(next, previous);
            }}
          >
            {LEAD_STATUSES.map((status) => (
              <option key={status} value={status}>
                {leadStatusLabel(status)}
              </option>
            ))}
          </select>
        )}
      </Field>

      <Field id={uid + '-notes'} label={SCOUT.notesLabel}>
        {(control) => (
          <textarea
            {...control}
            className="textarea text-sm font-semibold"
            style={{ height: 'auto', minHeight: 72 }}
            rows={3}
            maxLength={SPOT_LIMITS.notesMax}
            placeholder={SCOUT.notesPlaceholder}
            value={draft}
            onFocus={() => {
              focused.current = true;
              keepTyped.current = false;
            }}
            onChange={(e) => {
              setDraft(e.target.value);
              if (noteState !== 'idle') setNoteState('idle');
            }}
            onBlur={saveNotes}
          />
        )}
      </Field>
      <p role="status" aria-live="polite" className="-mt-2 min-h-[16px] text-xs font-bold" style={{ color: 'var(--body)' }}>
        {noteState === 'saving' ? SCOUT.notesSaving : null}
        {noteState === 'saved' ? (
          <span className="inline-flex items-center gap-1">
            <Check size={13} aria-hidden strokeWidth={3} /> {SCOUT.notesSaved}
          </span>
        ) : null}
        {noteState === 'failed' ? SCOUT.notesFailed : null}
      </p>
    </div>
  );
}
