import type { DraftStop } from '../../../stores/truckPlanDraftStore';

export interface EventTermsFormProps {
  /** The event stop being edited. */
  stop: DraftStop;
  /** The fields that changed; the planner patches its draft with them. */
  onChange: (patch: Partial<DraftStop>) => void;
}

/**
 * STUB (docs/truck-planner/05_FRONTEND.md 4.5 rule 5 and 9.2). The Scout, suggestions, events and
 * catering package replaces the body: name, place, expected attendance, food vendors, kind of event
 * and the fee.
 */
export default function EventTermsForm(_props: EventTermsFormProps) {
  return (
    <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
      Events and catering are not built yet.
    </p>
  );
}
