import { type ReactNode, useEffect, useRef, useState } from 'react';
import {
  TrendingUp, TrendingDown, ArrowLeftRight, RefreshCw, Scissors,
  Check, X, Info, Sparkles,
} from 'lucide-react';
import { type Recommendation } from '../../stores/restaurantStore';
import { MoneyStat, FreshnessChip } from './index';
import { useRecommendationAction } from './useRecommendationAction';

/**
 * The unified Carafe recommendation card. Used identically on the
 * war-room ("Top Move"), the menu page recs strip, and any future full
 * recs list. Three layouts, all driven by `density`:
 *
 *   • 'hero'    — large dollar figure (MoneyStat size="xl"), full
 *                  Accept/Dismiss/Why-this row. Used for the Top Move tile
 *                  where this is the *only* card on screen.
 *   • 'comfortable' — medium dollar figure, Accept primary + icon Dismiss
 *                  + Why-this collapse. Default; used in the menu page list.
 *   • 'compact' — small dollar figure, icon-only Accept/Dismiss; Why-this
 *                  collapse below. For future dense list views.
 *
 * Accept/dismiss is optimistic via useRecommendationAction(): the rec's
 * status flips in restaurantStore inside the same tick the button is
 * pressed (<<100ms), the server call follows, and an undo toast lands for
 * 5 seconds. The card itself animates out with .rec-card-collapse so the
 * next rec slides into its slot.
 */

export type RecKind = Recommendation['kind'];

type Props = {
  rec: Recommendation;
  /** Free-text item name. War-room passes from OverviewTopMove.menu_item_name;
   *  MenuPage passes from menuItems by lookup. Null hides the row. */
  itemName?: string | null;
  /** Optional fallback values when payload doesn't carry them (war-room). */
  fallbackPrice?: number | null;
  fallbackPlateCost?: number | null;
  density?: 'hero' | 'comfortable' | 'compact';
  /** Badge in the top-right (e.g. "From digest"). */
  badge?: ReactNode;
  /** Called after the decision animation finishes — host can do bookkeeping
   *  (e.g. advance the war-room queue). Receives the decision direction. */
  onAfterDecide?: (action: 'accept' | 'dismiss') => void;
  /** Read-only mode — hides Accept / Dismiss but keeps Why-this and the
   *  visual layout. Used for synthesized recs (daypart slow-window
   *  suggestions) that don't have a real recommendations row to act on. */
  readonly?: boolean;
  className?: string;
};

const KIND_META: Record<RecKind, { label: string; Icon: typeof TrendingUp; tone: 'positive' | 'negative' | 'neutral' }> = {
  price_raise: { label: 'Raise price',  Icon: TrendingUp,    tone: 'positive' },
  price_lower: { label: 'Lower price',  Icon: TrendingDown,  tone: 'neutral'  },
  reposition:  { label: 'Reposition',   Icon: ArrowLeftRight, tone: 'neutral'  },
  reprice:     { label: 'Reprice',      Icon: RefreshCw,     tone: 'neutral'  },
  cut:         { label: 'Cut from menu', Icon: Scissors,     tone: 'negative' },
};

export default function RecommendationCard({
  rec,
  itemName = null,
  fallbackPrice = null,
  fallbackPlateCost = null,
  density = 'comfortable',
  badge,
  onAfterDecide,
  readonly = false,
  className,
}: Props) {
  const { accept, dismiss } = useRecommendationAction();
  const [whyOpen, setWhyOpen] = useState(false);
  const [decided, setDecided] = useState<null | 'accept' | 'dismiss'>(null);
  const reducedMotion = useRef(
    typeof window !== 'undefined'
      && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches,
  ).current;
  const articleRef = useRef<HTMLElement>(null);

  // After the collapse animation completes, tell the host to drop us.
  useEffect(() => {
    if (!decided) return;
    const t = window.setTimeout(() => {
      onAfterDecide?.(decided);
    }, reducedMotion ? 0 : 320);
    return () => window.clearTimeout(t);
  }, [decided, onAfterDecide, reducedMotion]);

  const meta = KIND_META[rec.kind] ?? KIND_META.reprice;
  const Icon = meta.Icon;
  const dollars = Math.round(rec.dollar_estimate_cents / 100);

  function doAccept() {
    if (decided) return;
    setWhyOpen(false);
    setDecided('accept');
    accept(rec);
  }
  function doDismiss() {
    if (decided) return;
    setWhyOpen(false);
    setDecided('dismiss');
    dismiss(rec);
  }

  // ── visual scales by density ────────────────────────────────────────
  const sizing = {
    hero:        { figureSize: 'xl' as const, pad: 'p-4 sm:p-5', moneyTone: 'positive' as const },
    comfortable: { figureSize: 'lg' as const, pad: 'p-4',         moneyTone: meta.tone === 'negative' ? 'neutral' as const : 'positive' as const },
    compact:     { figureSize: 'md' as const, pad: 'p-3',         moneyTone: meta.tone === 'negative' ? 'neutral' as const : 'positive' as const },
  }[density];

  return (
    <article
      ref={articleRef}
      data-rec-id={rec.id}
      aria-label={`${meta.label} recommendation worth $${dollars}/mo`}
      className={
        'border-2 rounded-xl flex flex-col gap-3 ' +
        sizing.pad + ' ' +
        (decided ? 'rec-card-collapse ' : '') +
        (className ?? '')
      }
      style={{
        background: 'white',
        borderColor: density === 'hero' ? 'var(--brand-light)' : 'var(--line-soft)',
      }}
    >
      <div className="flex items-start gap-3">
        <span
          aria-hidden
          className="inline-flex items-center justify-center w-10 h-10 rounded-lg flex-shrink-0"
          style={{
            background: meta.tone === 'negative' ? 'var(--money-negative-bg)' : 'var(--brand-light)',
            color:      meta.tone === 'negative' ? 'var(--money-negative)'    : 'var(--brand)',
          }}
        >
          <Icon size={18} strokeWidth={2.4} />
        </span>

        <div className="flex-1 min-w-0">
          <div className="flex items-start justify-between gap-2 mb-1">
            <div className="text-[10px] font-bold uppercase tracking-wider truncate" style={{ color: 'var(--slate)' }}>
              {meta.label}
            </div>
            {badge && <div className="flex-shrink-0">{badge}</div>}
          </div>

          <MoneyStat
            label=""
            value={dollars}
            size={sizing.figureSize}
            tone={sizing.moneyTone}
            footer={<span style={{ color: 'var(--slate)' }}>per month</span>}
            durationMs={decided ? 0 : 240}
            className="-mt-0.5"
          />

          {itemName && (
            <div className="font-semibold text-sm mt-2" style={{ color: 'var(--ink)' }}>
              {itemName}
            </div>
          )}
          {rec.narrative && density !== 'compact' && (
            <p className="text-sm mt-1 leading-snug" style={{ color: 'var(--body)' }}>
              {rec.narrative}
            </p>
          )}
        </div>

        {/* Found-time chip — top-right corner, separate from kind label so
            it doesn't compete for attention with the dollar figure. */}
        {rec.created_at && (
          <div className="flex-shrink-0">
            <FreshnessChip timestamp={rec.created_at} label="found" size="xs" />
          </div>
        )}
      </div>

      {/* ── Decision row ────────────────────────────────────────────── */}
      {readonly ? (
        // Read-only: keep the Why-this affordance so the explainability
        // path still works, but drop Accept/Dismiss since the host doesn't
        // have a real recommendations row to act on.
        <div className="flex items-center gap-2 flex-wrap">
          <button
            type="button"
            onClick={() => setWhyOpen((v) => !v)}
            aria-expanded={whyOpen}
            aria-controls={`rec-why-${rec.id}`}
            className="inline-flex items-center justify-center gap-1.5 px-3 min-h-[44px] min-w-[44px] rounded-lg text-sm font-semibold border hover:bg-slate-50"
            style={{ color: 'var(--slate)', borderColor: 'var(--line)' }}
          >
            <Info size={14} /> Why this?
          </button>
        </div>
      ) : !decided ? (
        <div className="flex items-center gap-2 flex-wrap">
          <button
            type="button"
            onClick={doAccept}
            className="flex-1 min-w-[120px] inline-flex items-center justify-center gap-1.5 px-3 min-h-[44px] rounded-lg text-sm font-bold text-white"
            style={{ background: 'var(--money-positive)' }}
          >
            <Check size={16} /> Accept
          </button>
          <button
            type="button"
            onClick={doDismiss}
            aria-label="Dismiss recommendation"
            className="inline-flex items-center justify-center gap-1.5 px-3 min-h-[44px] min-w-[44px] rounded-lg text-sm font-semibold border hover:bg-slate-50"
            style={{ color: 'var(--slate)', borderColor: 'var(--line)' }}
          >
            <X size={16} /> {density === 'compact' ? '' : 'Dismiss'}
          </button>
          <button
            type="button"
            onClick={() => setWhyOpen((v) => !v)}
            aria-expanded={whyOpen}
            aria-controls={`rec-why-${rec.id}`}
            className="inline-flex items-center justify-center gap-1.5 px-3 min-h-[44px] min-w-[44px] rounded-lg text-sm font-semibold border hover:bg-slate-50"
            style={{ color: 'var(--slate)', borderColor: 'var(--line)' }}
          >
            <Info size={14} /> {density === 'compact' ? '' : 'Why this?'}
          </button>
        </div>
      ) : (
        <DecisionFeedback action={decided} />
      )}

      {/* ── "Why this?" expander ─────────────────────────────────────── */}
      {whyOpen && !decided && (
        <div
          id={`rec-why-${rec.id}`}
          className="card-expand border rounded-lg p-3 text-xs leading-relaxed"
          style={{
            background: 'var(--bg-panel)',
            borderColor: 'var(--line-soft)',
            color: 'var(--body)',
          }}
        >
          <WhyExplanation
            rec={rec}
            fallbackPrice={fallbackPrice}
            fallbackPlateCost={fallbackPlateCost}
          />
        </div>
      )}
    </article>
  );
}

/* ── Decision feedback ───────────────────────────────────────────────────
   A single 44px-tall row that replaces the action buttons the instant a
   decision lands. Restrained: a check (or X) badge + one-line confirm.
   No confetti, no emoji. */
function DecisionFeedback({ action }: { action: 'accept' | 'dismiss' }) {
  const isAccept = action === 'accept';
  return (
    <div
      className="flex items-center gap-2 min-h-[44px] px-3 rounded-lg font-semibold text-sm"
      style={{
        background: isAccept ? 'var(--money-positive-bg)' : 'var(--bg-panel)',
        color:      isAccept ? 'var(--money-positive)'    : 'var(--slate)',
      }}
      role="status"
      aria-live="polite"
    >
      <span
        className="rec-decision-check inline-flex items-center justify-center w-6 h-6 rounded-full text-white"
        style={{ background: isAccept ? 'var(--money-positive)' : 'var(--muted)' }}
      >
        {isAccept ? <Check size={14} strokeWidth={3} /> : <X size={14} strokeWidth={3} />}
      </span>
      <span>{isAccept ? 'Accepted — measuring impact' : 'Dismissed'}</span>
      <Sparkles size={12} aria-hidden className="ml-auto opacity-50" />
    </div>
  );
}

/* ── Why-this explanation ────────────────────────────────────────────────
   Lifts the math out of the rec payload the same way the previous war-room
   inline did. Lives here now so the menu page and recs list show the same
   explanation. */
function WhyExplanation({
  rec, fallbackPrice, fallbackPlateCost,
}: {
  rec: Recommendation;
  fallbackPrice: number | null;
  fallbackPlateCost: number | null;
}) {
  const payload = rec.payload ?? {};
  const priceDelta = num(payload['price_delta_cents']);
  const baselinePrice = num(payload['baseline_price_cents']) ?? fallbackPrice;
  const newPrice = num(payload['new_price_cents'])
    ?? (baselinePrice != null && priceDelta != null ? baselinePrice + priceDelta : null);
  const monthlyQty = num(payload['est_monthly_qty']);
  const plateCost = num(payload['plate_cost_cents']) ?? fallbackPlateCost;
  return (
    <>
      <div className="font-bold text-[10px] uppercase tracking-wider mb-1.5" style={{ color: 'var(--slate)' }}>
        How we got to {formatUsd(rec.dollar_estimate_cents)}/mo
      </div>
      <ul className="space-y-0.5">
        {baselinePrice != null && (
          <li>Current price: <strong>{formatUsd(baselinePrice)}</strong></li>
        )}
        {newPrice != null && (
          <li>
            Suggested price: <strong>{formatUsd(newPrice)}</strong>
            {priceDelta != null && <> ({priceDelta >= 0 ? '+' : ''}{formatUsd(priceDelta)})</>}
          </li>
        )}
        {plateCost != null && (
          <li>Plate cost: <strong>{formatUsd(plateCost)}</strong></li>
        )}
        {monthlyQty != null && (
          <li>Recent sales pace: <strong>{monthlyQty.toLocaleString()}/mo</strong> (from your POS)</li>
        )}
        {priceDelta != null && monthlyQty != null && (
          <li
            className="pt-1 mt-1 border-t"
            style={{ color: 'var(--ink)', borderColor: 'var(--line-soft)' }}
          >
            {formatUsd(priceDelta)} × {monthlyQty}/mo = <strong>{formatUsd(rec.dollar_estimate_cents)}/mo</strong>
          </li>
        )}
      </ul>
      <div className="mt-2 text-[11px]" style={{ color: 'var(--slate)' }}>
        We don't claim measured impact until we've watched 14 days of sales after you accept.
      </div>
    </>
  );
}

function num(v: any): number | null {
  if (v === null || v === undefined) return null;
  const n = Number(v);
  return Number.isFinite(n) ? n : null;
}

function formatUsd(cents: number | null | undefined): string {
  if (cents == null) return '—';
  return '$' + Math.round(cents / 100).toLocaleString();
}
