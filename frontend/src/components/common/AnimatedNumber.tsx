import { useEffect, useRef, useState } from 'react';

/**
 * VT12 — animated counter. Eases from 0 to `value` over `durationMs` using
 * requestAnimationFrame + an ease-out cubic curve. Re-animates whenever
 * `value` changes by more than a trivial epsilon.
 *
 * Respects prefers-reduced-motion: when the user has opted out, the final
 * value is shown immediately with no animation.
 */
export default function AnimatedNumber({
  value,
  durationMs = 350,
  format = (n) => Math.round(n).toLocaleString(),
  className,
}: {
  value: number | null | undefined;
  durationMs?: number;
  format?: (n: number) => string;
  className?: string;
}) {
  const [display, setDisplay] = useState<number>(value ?? 0);
  const fromRef = useRef<number>(value ?? 0);
  const targetRef = useRef<number>(value ?? 0);

  useEffect(() => {
    if (value == null) return;
    const reduced = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    if (reduced) { setDisplay(value); return; }
    fromRef.current = display;
    targetRef.current = value;
    const start = performance.now();
    let raf = 0;
    const tick = (now: number) => {
      const t = Math.min(1, (now - start) / durationMs);
      // ease-out cubic — fast at start, settling toward the target.
      const eased = 1 - Math.pow(1 - t, 3);
      setDisplay(fromRef.current + (targetRef.current - fromRef.current) * eased);
      if (t < 1) raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(raf);
    // intentionally exclude `display` so re-renders don't restart the anim
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, durationMs]);

  if (value == null) return <span className={className}>—</span>;
  return <span className={className}>{format(display)}</span>;
}
