import { forwardRef } from 'react';
import { ArrowDown, ArrowUp, ArrowUpFromLine, Target, X } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { RESULT_TEXT, type ResultText } from '../../../utils/truck/logForm';
import type { Verdict } from '../../../utils/truck/logView';
import { FIELD } from '../../../utils/truck/wording';
import { RangeValue } from '../ui';

// One glyph per verdict, so the tag never rests on colour. None of them says good or bad: a count
// above the range is not a success and one below it is not a failure, and a sold-out count is a floor.
const VERDICT_GLYPH: Record<Verdict, LucideIcon> = {
  inside: Target,
  above: ArrowUp,
  below: ArrowDown,
  sold_out: ArrowUpFromLine,
};

/** How a logged count sits against its estimate, as a neutral tag: "inside the range", ... */
export function VerdictTag({ verdict, words }: { verdict: Verdict; words: string }) {
  const Glyph = VERDICT_GLYPH[verdict];
  return (
    <span className="tp-chip">
      <Glyph size={13} strokeWidth={2.75} aria-hidden className="flex-none" />
      {words}
    </span>
  );
}

export interface ResultCardProps {
  /** The sentences of the card, from `resultCardText`. */
  text: ResultText;
  onDismiss: () => void;
  /** Shown under the sentences: where the owner can see more. */
  onShowAccuracy?: () => void;
}

/**
 * What the page says after a service is saved (docs/truck-planner/05_FRONTEND.md 4.7): the count,
 * the estimate that was kept with the service and how the count sits against it, then what the
 * owner's results now do to the estimates. The estimate is the stored one, with its range and its
 * label; a sold-out count is called a minimum.
 *
 * The page renders the card inside a polite live region that is always there, so the sentences are
 * read out when they appear and the focus can stay with the form for the next service.
 */
const ResultCard = forwardRef<HTMLElement, ResultCardProps>(function ResultCard({ text, onDismiss, onShowAccuracy }, ref) {
  return (
    <section ref={ref} aria-label="The service you just saved" className="bg-white rounded-xl border p-4 sm:p-5" style={{ borderColor: 'var(--line-soft)' }}>
      <div className="flex items-start justify-between gap-3">
        <h2 className="text-base font-extrabold leading-snug" style={{ color: 'var(--ink)' }}>
          {text.headline}
        </h2>
        <button
          type="button"
          onClick={onDismiss}
          aria-label={FIELD.close}
          title={FIELD.close}
          className="-mr-2 -mt-2 inline-flex h-11 w-11 flex-none items-center justify-center rounded-lg"
          style={{ color: 'var(--body)' }}
        >
          <X size={18} aria-hidden />
        </button>
      </div>

      {text.estimate !== null ? (
        <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1.5 text-sm font-semibold" style={{ color: 'var(--body)' }}>
          <span>{RESULT_TEXT.estimateWas}</span>
          <RangeValue estimate={text.estimate} unit="orders" layout="inline" size="sm" />
          {text.verdict !== null && text.verdictWords !== null ? <VerdictTag verdict={text.verdict} words={text.verdictWords} /> : null}
        </div>
      ) : null}
      {text.compared !== null ? (
        <p className="mt-1.5 text-sm font-semibold" style={{ color: 'var(--ink)' }}>
          {text.compared}
        </p>
      ) : null}

      <div className="mt-3 border-t pt-3" style={{ borderColor: 'var(--line-soft)' }}>
        <p className="text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
          {text.factors}
        </p>
        {text.before !== null ? (
          <p className="mt-0.5 text-[13px] font-semibold tabular-nums" style={{ color: 'var(--body)' }}>
            {text.before}
          </p>
        ) : null}
        {onShowAccuracy !== undefined ? (
          <button
            type="button"
            className="mt-1.5 inline-flex min-h-[44px] md:min-h-0 items-center text-[13px] font-bold underline underline-offset-2"
            style={{ color: 'var(--ink)' }}
            onClick={onShowAccuracy}
          >
            See how the estimates are doing
          </button>
        ) : null}
      </div>
    </section>
  );
});

export default ResultCard;
