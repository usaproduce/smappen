import { useEffect, useId, useState } from 'react';
import type { DraftStop } from '../../../stores/truckPlanDraftStore';
import {
  STOP_TERMS,
  STOP_TERM_LIMITS,
  cateringDraftOf,
  cateringMissing,
  cateringTermsOf,
  foodCostHelp,
  missingSentence,
  sameCateringTerms,
  type CateringDraft,
} from '../../../utils/truck/scoutView';
import { useTruck } from '../data';
import { MoneyField, NumberField } from '../ui';
import { StopFormNote, StopPlaceField } from './EventTermsForm';

export interface CateringTermsFormProps {
  /** The catering stop being edited. */
  stop: DraftStop;
  /** The fields that changed; the planner patches its draft with them. */
  onChange: (patch: Partial<DraftStop>) => void;
}

/**
 * The details of a catering job (docs/truck-planner/05_FRONTEND.md 4.5 rule 5): where it is, the
 * headcount, the price per head or the guaranteed minimum (one of the two is required; the job pays
 * whichever comes to more), and the food cost of the job when the owner knows it. The name is the
 * field at the top of the stop's card.
 *
 * A catering job is contracted, not estimated: once the headcount and a price are in, the model
 * returns every line of it as a fixed amount, without a range.
 */
export default function CateringTermsForm({ stop, onChange }: CateringTermsFormProps) {
  const ids = useId();
  const { profile } = useTruck();
  const [draft, setDraft] = useState<CateringDraft>(() => cateringDraftOf(stop.catering));

  // Terms that arrive from outside (the saved day, an undo) replace what the fields hold.
  const saved = stop.catering;
  useEffect(() => {
    if (saved !== null) setDraft((current) => (sameCateringTerms(cateringTermsOf(current), saved) ? current : cateringDraftOf(saved)));
  }, [saved]);

  const commit = (next: CateringDraft) => {
    setDraft(next);
    const terms = cateringTermsOf(next);
    if (!sameCateringTerms(terms, stop.catering)) onChange({ catering: terms });
  };

  const missing = missingSentence(cateringMissing(stop, draft));

  return (
    <div className="space-y-3 border-t pt-3" style={{ borderColor: 'var(--line-soft)' }}>
      <StopPlaceField
        idPrefix={ids}
        point={stop.point}
        address={stop.address}
        onChange={(place) => onChange({ point: place.point, address: place.address, ...(stop.label.trim() === '' && place.name !== '' ? { label: place.name } : {}) })}
      />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <NumberField
          id={ids + 'headcount'}
          label={STOP_TERMS.headcount}
          value={draft.headcount}
          onCommit={(headcount) => commit({ ...draft, headcount })}
          integer
          min={STOP_TERM_LIMITS.headcountMin}
          max={STOP_TERM_LIMITS.headcountMax}
          required
        />
        <MoneyField
          id={ids + 'food-cost'}
          label={STOP_TERMS.foodCost}
          value={draft.foodCost}
          onCommit={(foodCost) => commit({ ...draft, foodCost })}
          min={STOP_TERM_LIMITS.foodCostMin}
          max={STOP_TERM_LIMITS.foodCostMax}
          help={foodCostHelp(profile.food_cost_pct)}
        />
      </div>

      <div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <MoneyField
            id={ids + 'price'}
            label={STOP_TERMS.pricePerHead}
            value={draft.pricePerHead}
            onCommit={(pricePerHead) => commit({ ...draft, pricePerHead })}
            min={STOP_TERM_LIMITS.priceMin}
            max={STOP_TERM_LIMITS.priceMax}
          />
          <MoneyField
            id={ids + 'guarantee'}
            label={STOP_TERMS.guarantee}
            value={draft.guarantee}
            onCommit={(guarantee) => commit({ ...draft, guarantee })}
            min={STOP_TERM_LIMITS.guaranteeMin}
            max={STOP_TERM_LIMITS.guaranteeMax}
          />
        </div>
        <p className="mt-1 text-xs font-semibold" style={{ color: 'var(--body)' }}>
          {STOP_TERMS.priceHelp}
        </p>
      </div>

      {missing !== null ? <StopFormNote strong>{missing}</StopFormNote> : null}
      <StopFormNote>{STOP_TERMS.cateringNote}</StopFormNote>
    </div>
  );
}
