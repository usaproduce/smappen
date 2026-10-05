export interface BestWeekPanelProps {
  /** The Monday the week starts on. */
  weekStart: string;
  /** Dates of the week that already have a plan: a suggestion never replaces one. */
  plannedDates: string[];
  /** Draft plans were created for empty days: the week reads its plans again. */
  onApplied: () => void;
}

/**
 * STUB (docs/truck-planner/05_FRONTEND.md 4.6 and 9.2). The Scout and suggestions package replaces
 * the body: "Suggest a week", the suggested days and days off, and "Use for the empty days".
 *
 * Until then it renders nothing.
 */
export default function BestWeekPanel(_props: BestWeekPanelProps) {
  return null;
}
