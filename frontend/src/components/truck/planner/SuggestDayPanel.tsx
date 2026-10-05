import type { DayContext, Suggestion } from '../../../utils/truck/model';

export interface SuggestDayPanelProps {
  date: string;
  /** The planner's "Treat this day as" for this date. */
  treatAs: DayContext['treat_as'];
  open: boolean;
  onClose: () => void;
  /** "Use this plan": the planner replaces the draft's stops with the suggestion's. */
  onUse: (s: Suggestion) => void;
}

/**
 * STUB (docs/truck-planner/05_FRONTEND.md 4.5 rule 6 and 9.2). The Scout and suggestions package
 * replaces the body: up to three suggested days for the date, each with its stops, its take-home
 * and "Use this plan".
 *
 * Until then it renders nothing.
 */
export default function SuggestDayPanel(_props: SuggestDayPanelProps) {
  return null;
}
