import { Lock, Signal, SignalHigh, SignalLow, SignalMedium } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { Estimate } from '../../../utils/truck/model';
import { confidenceLabel, confidenceSentence, confidenceSpoken } from '../../../utils/truck/wording';
import HintChip from './HintChip';
import { CONFIDENCE_GLYPH } from './kit';

export interface ConfidenceChipProps {
  confidence: Estimate['confidence'];
  /** 24 px tall (md) or 20 px (sm). */
  size?: 'sm' | 'md';
  /** Hover, focus or tap opens the sentence of the label. */
  hint?: boolean;
}

type Glyph = (typeof CONFIDENCE_GLYPH)[keyof typeof CONFIDENCE_GLYPH];

const GLYPHS: Record<Glyph, LucideIcon> = {
  'signal-low': SignalLow,
  'signal-medium': SignalMedium,
  'signal-high': SignalHigh,
  signal: Signal,
  lock: Lock,
};

// The signal glyphs draw two to five bars from the left of their box; the empty rest is cropped,
// so the word follows the bars at the same distance on every chip.
const DRAWN: Record<Glyph, number> = {
  'signal-low': 9.5 / 24,
  'signal-medium': 14.5 / 24,
  'signal-high': 19.5 / 24,
  signal: 1,
  lock: 1,
};

/**
 * The confidence label of an estimate (docs/truck-planner/05_FRONTEND.md 3.3, wording 6.2). Labels
 * and sentences are fixed. Each label has its own glyph, so it never depends on colour.
 */
export default function ConfidenceChip({ confidence, size = 'md', hint = false }: ConfidenceChipProps) {
  return (
    <HintChip
      icon={GLYPHS[CONFIDENCE_GLYPH[confidence]]}
      glyphShare={DRAWN[CONFIDENCE_GLYPH[confidence]]}
      label={confidenceLabel(confidence)}
      sentence={confidenceSentence(confidence)}
      spoken={confidenceSpoken(confidence)}
      size={size}
      hint={hint}
    />
  );
}
