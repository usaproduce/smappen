export interface ScoutDotsLayerProps {
  /** The "Scout results" toggle of the map tools. */
  enabled: boolean;
  /** A dot was picked: the map opens the spot card at that point. */
  onPick: (p: { lat: number; lng: number; placeKey: string }) => void;
}

/**
 * STUB (docs/truck-planner/05_FRONTEND.md 4.2 and 9.2). The Scout package replaces the body: one
 * 12 px dot per place of the cached Scout answer, labelled "{position}. {name}".
 *
 * Until then it renders nothing.
 */
export default function ScoutDotsLayer(_props: ScoutDotsLayerProps) {
  return null;
}
