import { Calculator, Hourglass, PenLine, Ruler } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { SeedTag as SeedTagKey } from '../../../utils/truck/model';
import { SEED_TAG_TEXT } from '../../../utils/truck/wording';
import HintChip from './HintChip';

export interface SeedTagProps {
  tag: SeedTagKey;
  size?: 'sm' | 'md';
}

const GLYPHS: Record<SeedTagKey, LucideIcon> = {
  measured: Ruler,
  derived: Calculator,
  assumed: PenLine,
  tuned: Hourglass,
};

/**
 * Where an assumption comes from: "Measured", "Derived", "Assumed" or "Placeholder" (wording 6.6).
 * A neutral chip like the confidence chip, with its hint. A seed tagged "tuned" is a placeholder
 * and never reads as a finding.
 */
export default function SeedTag({ tag, size = 'md' }: SeedTagProps) {
  const text = SEED_TAG_TEXT[tag];
  return <HintChip icon={GLYPHS[tag]} label={text.chip} sentence={text.hint} spoken={text.chip + ': ' + text.hint} size={size} hint />;
}
