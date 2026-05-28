import { useEffect, useState } from 'react';

/**
 * Small pill that tells the operator how fresh the underlying number is.
 * Three semantic states keyed off the --fresh-* tokens: fresh / aging / stale.
 *
 * Driven two ways:
 *   1) Pass a `timestamp` + optional thresholds and a `label` prefix; the
 *      chip computes the relative-time string itself and re-renders once
 *      a minute via an internal tick so "12m ago" stays current without
 *      a parent refetch.
 *   2) Pass an explicit `state` + `text` for cases where the freshness
 *      isn't a single timestamp (e.g. "manual entry", "draft saved").
 */

export type FreshnessState = 'fresh' | 'aging' | 'stale';

type CommonProps = {
  className?: string;
  size?: 'xs' | 'sm';
};

type TimestampProps = CommonProps & {
  timestamp: string | number | Date;
  label?: string;
  freshUntilMinutes?: number;
  staleAfterMinutes?: number;
  state?: never;
  text?: never;
};

type ExplicitProps = CommonProps & {
  state: FreshnessState;
  text: string;
  timestamp?: never;
  label?: never;
  freshUntilMinutes?: never;
  staleAfterMinutes?: never;
};

type Props = TimestampProps | ExplicitProps;

export default function FreshnessChip(props: Props) {
  const [, setTick] = useState(0);
  useEffect(() => {
    if (!('timestamp' in props) || props.timestamp == null) return;
    const id = window.setInterval(() => setTick((n) => n + 1), 60_000);
    return () => window.clearInterval(id);
  }, [props]);

  let state: FreshnessState;
  let text: string;

  if ('state' in props && props.state) {
    state = props.state;
    text = props.text;
  } else {
    const ts = props.timestamp instanceof Date
      ? props.timestamp.getTime()
      : typeof props.timestamp === 'number'
        ? props.timestamp
        : Date.parse(String(props.timestamp).replace(' ', 'T'));
    const ageMin = Math.max(0, (Date.now() - ts) / 60_000);
    const freshUntil = props.freshUntilMinutes ?? 30;
    const staleAfter = props.staleAfterMinutes ?? 24 * 60;
    state = ageMin <= freshUntil ? 'fresh' : ageMin <= staleAfter ? 'aging' : 'stale';
    const prefix = props.label ?? 'synced';
    text = `${prefix} ${formatAge(ageMin)}`;
  }

  const sizePx = props.size === 'xs' ? '10px' : '11px';
  const colors = state === 'fresh'
    ? { fg: 'var(--fresh-fresh)', bg: 'var(--fresh-fresh-bg)' }
    : state === 'aging'
      ? { fg: 'var(--fresh-aging)', bg: 'var(--fresh-aging-bg)' }
      : { fg: 'var(--fresh-stale)', bg: 'var(--fresh-stale-bg)' };

  return (
    <span
      className={
        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold whitespace-nowrap ' +
        (props.className ?? '')
      }
      style={{ fontSize: sizePx, color: colors.fg, background: colors.bg, lineHeight: 1.4 }}
      title={text}
      data-freshness={state}
    >
      <span
        aria-hidden
        className="inline-block rounded-full"
        style={{
          width: 6,
          height: 6,
          background: 'currentColor',
          opacity: state === 'stale' ? 0.7 : 1,
        }}
      />
      {text}
    </span>
  );
}

function formatAge(minutes: number): string {
  if (minutes < 1) return 'just now';
  if (minutes < 60) return `${Math.round(minutes)}m ago`;
  const hours = minutes / 60;
  if (hours < 24) return `${Math.round(hours)}h ago`;
  const days = hours / 24;
  if (days < 30) return `${Math.round(days)}d ago`;
  const months = days / 30;
  return `${Math.round(months)}mo ago`;
}
