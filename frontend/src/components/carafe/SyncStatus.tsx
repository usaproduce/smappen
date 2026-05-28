import { type ReactNode } from 'react';
import {
  Plug, RefreshCw, CheckCircle2, AlertOctagon, Clock, Loader2,
} from 'lucide-react';
import FreshnessChip from './FreshnessChip';

/**
 * POS integration status — one component drives every state of the POS
 * connection card. Every state pairs an icon + verbal label + tint, so
 * the signal survives color-blindness and the WCAG "color is not the
 * only means" rule. Dark-mode contrast is held by the design tokens
 * (--money-positive / --fresh-aging / --money-negative all auto-flip).
 *
 *   not_connected — operator hasn't run the OAuth dance
 *   connecting    — OAuth in flight (we're handing off to Square)
 *   syncing       — sync job running; progress bar
 *   synced        — happy path; FreshnessChip on last_synced_at
 *   stale         — last_synced_at older than the threshold (default 48h)
 *   error         — actionable message + retry button
 *
 * Stale is computed automatically when the timestamp is older than
 * `staleAfterMinutes`. Callers can also force a state via `state`.
 */

export type SyncState =
  | 'not_connected'
  | 'connecting'
  | 'syncing'
  | 'synced'
  | 'stale'
  | 'error';

type Props = {
  provider: string;        // "Square", "Toast"…
  /** Force a specific state. If omitted, derived from the other props. */
  state?: SyncState;
  /** ISO string of the last successful sync. */
  lastSyncedAt?: string | null;
  /** ISO of the last actual sale ingested, if known. Shown as a second chip. */
  lastSaleAt?: string | null;
  /** When true, the component renders the `syncing` state regardless of timestamps. */
  isSyncing?: boolean;
  /** When true, the component renders the `connecting` (OAuth) state. */
  isConnecting?: boolean;
  /** Error message; renders the `error` state. */
  errorMessage?: string | null;
  /** Minutes after which a synced state degrades to `stale`. Default 48 hours. */
  staleAfterMinutes?: number;
  /** Called when the operator clicks the primary CTA (Connect / Sync now / Retry). */
  onPrimary?: () => void;
  /** Optional secondary action — e.g. "Open Square" link. */
  secondary?: ReactNode;
  /** Compact mode — hides the description line. */
  compact?: boolean;
  /** Title override — defaults to "${provider} connected". */
  title?: string;
};

export default function SyncStatus(props: Props) {
  const {
    provider,
    state: forcedState,
    lastSyncedAt = null,
    lastSaleAt = null,
    isSyncing = false,
    isConnecting = false,
    errorMessage = null,
    staleAfterMinutes = 48 * 60,
    onPrimary,
    secondary,
    compact = false,
    title,
  } = props;

  const state = forcedState ?? deriveState({
    lastSyncedAt, isSyncing, isConnecting, errorMessage, staleAfterMinutes,
  });
  const meta = META[state](provider);
  const Icon = meta.Icon;

  return (
    <section
      role="status"
      aria-label={`${provider} ${meta.ariaState}`}
      className="rounded-xl border flex items-start gap-3 p-3 sm:p-4"
      style={{ background: 'white', borderColor: meta.borderColor }}
    >
      <span
        aria-hidden
        className="inline-flex items-center justify-center w-10 h-10 rounded-lg flex-shrink-0"
        style={{ background: meta.iconBg, color: meta.iconFg }}
      >
        {state === 'connecting' || state === 'syncing' ? (
          <Icon size={18} className="animate-spin" />
        ) : (
          <Icon size={18} strokeWidth={2.3} />
        )}
      </span>

      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          <span className="font-bold text-sm" style={{ color: 'var(--ink)' }}>
            {title ?? meta.title}
          </span>
          {/* Status badge: icon + label, never color alone. */}
          <span
            aria-label={meta.ariaState}
            className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider"
            style={{ background: meta.badgeBg, color: meta.badgeFg }}
          >
            <Icon size={10} aria-hidden strokeWidth={2.6} />
            {meta.label}
          </span>
          {/* Freshness chip — only meaningful in synced/stale, but we show
              both timestamps when present so the operator sees not just
              "synced 12m ago" but "last sale 3m ago" too. */}
          {(state === 'synced' || state === 'stale') && lastSyncedAt && (
            <FreshnessChip
              timestamp={lastSyncedAt}
              label="POS synced"
              size="xs"
              staleAfterMinutes={staleAfterMinutes}
            />
          )}
          {(state === 'synced' || state === 'stale') && lastSaleAt && (
            <FreshnessChip
              timestamp={lastSaleAt}
              label="last sale"
              size="xs"
              staleAfterMinutes={staleAfterMinutes}
            />
          )}
        </div>

        {!compact && (
          <div className="text-xs mt-1" style={{ color: 'var(--slate)' }}>
            {state === 'error' && errorMessage
              ? errorMessage
              : meta.description}
          </div>
        )}

        {state === 'syncing' && (
          <div className="mt-2">
            <div
              className="progress-bar"
              role="progressbar"
              aria-label="Sync in progress"
              aria-valuemin={0}
              aria-valuemax={100}
            >
              <span />
            </div>
          </div>
        )}
      </div>

      <div className="flex items-center gap-2 flex-shrink-0">
        {secondary}
        {onPrimary && meta.primaryLabel && (
          <button
            type="button"
            onClick={onPrimary}
            disabled={state === 'connecting' || state === 'syncing'}
            className="inline-flex items-center justify-center gap-1.5 px-3 min-h-[44px] rounded-lg text-xs font-bold disabled:opacity-60 disabled:cursor-not-allowed"
            style={{
              background: meta.primaryBg,
              color: meta.primaryFg,
              border: meta.primaryBorder,
            }}
          >
            {meta.primaryIcon && <meta.primaryIcon size={14} />}
            {meta.primaryLabel}
          </button>
        )}
      </div>
    </section>
  );
}

/* ── derive ─────────────────────────────────────────────────────────── */
function deriveState({
  lastSyncedAt, isSyncing, isConnecting, errorMessage, staleAfterMinutes,
}: {
  lastSyncedAt: string | null;
  isSyncing: boolean;
  isConnecting: boolean;
  errorMessage: string | null;
  staleAfterMinutes: number;
}): SyncState {
  if (isConnecting) return 'connecting';
  if (errorMessage) return 'error';
  if (isSyncing)   return 'syncing';
  if (!lastSyncedAt) return 'not_connected';
  const ts = Date.parse(String(lastSyncedAt).replace(' ', 'T'));
  if (!Number.isFinite(ts)) return 'synced';
  const ageMin = (Date.now() - ts) / 60_000;
  return ageMin > staleAfterMinutes ? 'stale' : 'synced';
}

/* ── per-state visual + copy ─────────────────────────────────────────── */
type StateMeta = {
  Icon: typeof Plug;
  ariaState: string;
  label: string;            // for the badge
  title: string;
  description: string;
  iconBg: string;
  iconFg: string;
  badgeBg: string;
  badgeFg: string;
  borderColor: string;
  primaryLabel?: string;
  primaryIcon?: typeof Plug;
  primaryBg: string;
  primaryFg: string;
  primaryBorder: string;
};

const META: Record<SyncState, (provider: string) => StateMeta> = {
  not_connected: (p) => ({
    Icon: Plug,
    ariaState: 'not connected',
    label: 'Not connected',
    title: `Connect ${p}`,
    description: `Without a POS feed we can't see what's selling. Connect once, then forget about it.`,
    iconBg: 'var(--bg-panel)',
    iconFg: 'var(--slate)',
    badgeBg: 'var(--bg-panel)',
    badgeFg: 'var(--slate)',
    borderColor: 'var(--line-soft)',
    primaryLabel: `Connect ${p}`,
    primaryIcon: Plug,
    primaryBg: 'var(--brand)',
    primaryFg: 'white',
    primaryBorder: '1px solid var(--brand)',
  }),
  connecting: (p) => ({
    Icon: Loader2,
    ariaState: 'connecting',
    label: 'Connecting',
    title: `Opening ${p}…`,
    description: 'Finish the authorization in the popup. We pick up the rest when you land back here.',
    iconBg: 'var(--brand-light)',
    iconFg: 'var(--brand)',
    badgeBg: 'var(--brand-light)',
    badgeFg: 'var(--brand)',
    borderColor: 'var(--brand-light)',
    primaryLabel: 'Working…',
    primaryIcon: Loader2,
    primaryBg: 'var(--bg-panel)',
    primaryFg: 'var(--slate)',
    primaryBorder: '1px solid var(--line)',
  }),
  syncing: (p) => ({
    Icon: RefreshCw,
    ariaState: 'syncing',
    label: 'Syncing',
    title: `Syncing ${p}`,
    description: 'Pulling menu items, sales, and labor since the last successful sync.',
    iconBg: 'var(--brand-light)',
    iconFg: 'var(--brand)',
    badgeBg: 'var(--brand-light)',
    badgeFg: 'var(--brand)',
    borderColor: 'var(--brand-light)',
    primaryLabel: 'Syncing…',
    primaryIcon: Loader2,
    primaryBg: 'var(--bg-panel)',
    primaryFg: 'var(--slate)',
    primaryBorder: '1px solid var(--line)',
  }),
  synced: (p) => ({
    Icon: CheckCircle2,
    ariaState: 'synced',
    label: 'Synced',
    title: `${p} connected`,
    description: `We pull every line item — that's the basis for the dollar moves below.`,
    iconBg: 'var(--money-positive-bg)',
    iconFg: 'var(--money-positive)',
    badgeBg: 'var(--money-positive-bg)',
    badgeFg: 'var(--money-positive)',
    borderColor: 'var(--line-soft)',
    primaryLabel: 'Sync now',
    primaryIcon: RefreshCw,
    primaryBg: 'white',
    primaryFg: 'var(--ink)',
    primaryBorder: '1px solid var(--line)',
  }),
  stale: (p) => ({
    Icon: Clock,
    ariaState: 'stale — re-sync recommended',
    label: 'Stale',
    title: `${p} hasn't synced recently`,
    description: 'Numbers below may be a day or two behind. Re-sync to refresh — takes about a minute.',
    iconBg: 'var(--fresh-aging-bg)',
    iconFg: '#92670E',
    badgeBg: 'var(--fresh-aging-bg)',
    badgeFg: '#92670E',
    borderColor: 'var(--fresh-aging-bg)',
    primaryLabel: 'Re-sync now',
    primaryIcon: RefreshCw,
    primaryBg: '#92670E',
    primaryFg: 'white',
    primaryBorder: '1px solid #92670E',
  }),
  error: (p) => ({
    Icon: AlertOctagon,
    ariaState: 'sync error',
    label: 'Sync error',
    title: `${p} sync failed`,
    description: 'See the error message below. Retry once you fix the underlying issue.',
    iconBg: 'var(--money-negative-bg)',
    iconFg: 'var(--money-negative)',
    badgeBg: 'var(--money-negative-bg)',
    badgeFg: 'var(--money-negative)',
    borderColor: 'var(--money-negative)',
    primaryLabel: 'Retry sync',
    primaryIcon: RefreshCw,
    primaryBg: 'var(--money-negative)',
    primaryFg: 'white',
    primaryBorder: '1px solid var(--money-negative)',
  }),
};
