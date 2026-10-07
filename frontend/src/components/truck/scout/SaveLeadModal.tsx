import { useId, useRef, useState } from 'react';
import { Info, TriangleAlert } from 'lucide-react';
import type { Visibility } from '../../../utils/truck/model';
import {
  SAVE_LEAD,
  leadSpotBody,
  saveLeadDraft,
  saveLeadErrors,
  saveLeadFields,
  typicalSizeHelp,
  type SaveLeadDraft,
  type ScoutCandidate,
} from '../../../utils/truck/scoutView';
import { SPOT_LIMITS } from '../../../utils/truck/spotSummary';
import { VISIBILITY_TEXT, placeTypeLabel } from '../../../utils/truck/wording';
import { useSaveLeadAsSpot } from '../data';
import { Field, Modal, NumberField, Toggle } from '../ui';

export interface SaveLeadModalProps {
  /** The place to save; null keeps the dialog closed. */
  candidate: ScoutCandidate | null;
  onClose: () => void;
}

const VISIBILITIES: Visibility[] = ['hidden', 'normal', 'prominent'];

/**
 * "Save as spot" (docs/truck-planner/05_FRONTEND.md 4.8, route 41): a scouted place becomes a saved
 * spot at the place's own point, linked to the place. The dialog asks for what the estimate of a
 * saved spot rests on: its name, the size of the host when the kind of place has one, whether the
 * truck is the only food there, and how easy the truck is to see.
 *
 * The body carries what is typed here and nothing else: no detail of a lookup is part of it.
 */
export default function SaveLeadModal({ candidate, onClose }: SaveLeadModalProps) {
  return (
    <Modal open={candidate !== null} onClose={onClose} title={SAVE_LEAD.title} size="sm">
      {candidate !== null ? <SaveLeadForm key={candidate.place.place_key} candidate={candidate} onClose={onClose} /> : null}
    </Modal>
  );
}

function SaveLeadForm({ candidate, onClose }: { candidate: ScoutCandidate; onClose: () => void }) {
  const ids = useId();
  const save = useSaveLeadAsSpot();
  const fields = saveLeadFields(candidate.result);
  const [draft, setDraft] = useState<SaveLeadDraft>(() => saveLeadDraft(candidate));
  const [tried, setTried] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const form = useRef<HTMLFormElement>(null);
  const errors = saveLeadErrors(draft, fields);
  const shown = tried ? errors : {};
  const busy = save.isPending;

  const change = (next: SaveLeadDraft) => {
    setDraft(next);
    setProblem(null);
  };

  const submit = () => {
    if (busy) return;
    // A number field that still shows its own refusal holds the save back too: what is on screen
    // there is not what would be saved.
    const refused = form.current === null ? null : form.current.querySelector<HTMLElement>('[aria-invalid="true"]');
    if (Object.keys(errors).length > 0 || refused !== null) {
      setTried(true);
      setProblem(SAVE_LEAD.check);
      if (refused !== null) refused.focus();
      return;
    }
    setProblem(null);
    save
      .mutateAsync({ placeKey: candidate.place.place_key, body: leadSpotBody(draft, fields) })
      .then(() => onClose())
      .catch(() => {
        // The hook has shown the server's sentence ("This place is already saved as a spot" as it is).
      });
  };

  return (
    <form
      ref={form}
      noValidate
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault();
        submit();
      }}
      // Enter in a field commits that field; only the button saves.
      onKeyDown={(e) => {
        if (e.key === 'Enter' && e.target instanceof HTMLInputElement) e.preventDefault();
      }}
    >
      <p className="text-[13px] font-semibold" style={{ color: 'var(--body)' }}>
        {placeTypeLabel(candidate.kind)}. The spot is saved at the point of this place.
      </p>

      <Field id={ids + 'name'} label={SAVE_LEAD.nameLabel} error={shown.name} required>
        {(control) => (
          <input
            {...control}
            type="text"
            className="input h-11 md:h-9 text-sm font-semibold"
            value={draft.name}
            maxLength={SPOT_LIMITS.nameMax}
            autoComplete="off"
            onChange={(e) => change({ ...draft, name: e.target.value })}
          />
        )}
      </Field>

      {fields.size !== 'none' && fields.sizeLabel !== null ? (
        <NumberField
          id={ids + 'size'}
          label={fields.sizeLabel}
          value={draft.size}
          onCommit={(size) => change({ ...draft, size })}
          min={SPOT_LIMITS.sizeMin}
          max={SPOT_LIMITS.sizeMax}
          required={fields.size === 'required'}
          placeholder={fields.typical !== null ? String(fields.typical) : undefined}
          help={fields.typical !== null ? typicalSizeHelp(fields.typical) : undefined}
          error={shown.size}
        />
      ) : null}

      {fields.onlyFood ? (
        <Toggle
          id={ids + 'only-food'}
          label={SAVE_LEAD.onlyFoodLabel}
          checked={draft.onlyFood}
          onChange={(onlyFood) => change({ ...draft, onlyFood })}
          help={SAVE_LEAD.onlyFoodHelp}
        />
      ) : null}

      {fields.note !== null ? (
        <p className="flex items-start gap-1.5 text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
          <Info size={14} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--slate)' }} />
          <span>{fields.note}</span>
        </p>
      ) : null}

      <fieldset>
        <legend className="label">{SAVE_LEAD.visibilityLabel}</legend>
        <div className="grid gap-2">
          {VISIBILITIES.map((level) => {
            const on = draft.visibility === level;
            const text = VISIBILITY_TEXT[level];
            return (
              <label
                key={level}
                className={'flex cursor-pointer items-start gap-2.5 rounded-lg border px-3 py-2.5 min-h-[44px] md:min-h-0' + (on ? '' : ' bg-white')}
                style={{ borderColor: on ? 'var(--brand)' : 'var(--line)', background: on ? 'var(--brand-light)' : undefined }}
              >
                <input
                  type="radio"
                  name={ids + 'visibility'}
                  className="mt-0.5 h-4 w-4 flex-none"
                  style={{ accentColor: 'var(--brand)' }}
                  checked={on}
                  onChange={() => change({ ...draft, visibility: level })}
                />
                <span className="min-w-0">
                  <span className="block text-sm font-bold leading-snug" style={{ color: 'var(--ink)' }}>
                    {text.label}
                  </span>
                  {text.help !== '' ? (
                    <span className="block text-xs font-semibold" style={{ color: 'var(--body)' }}>
                      {text.help}
                    </span>
                  ) : null}
                </span>
              </label>
            );
          })}
        </div>
      </fieldset>

      {problem !== null ? (
        <p role="alert" className="flex items-start gap-1.5 text-[13px] font-bold" style={{ color: 'var(--money-negative)' }}>
          <TriangleAlert size={14} aria-hidden className="mt-0.5 flex-none" />
          <span>{problem}</span>
        </p>
      ) : null}

      <div className="flex flex-wrap items-center justify-end gap-2 border-t pt-3" style={{ borderColor: 'var(--line-soft)' }}>
        <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm" onClick={onClose} disabled={busy}>
          {SAVE_LEAD.cancel}
        </button>
        <button type="submit" className="btn btn-primary h-11 md:h-9 px-4 text-sm" disabled={busy}>
          {busy ? SAVE_LEAD.saving : SAVE_LEAD.save}
        </button>
      </div>
    </form>
  );
}
