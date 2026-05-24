import toast from 'react-hot-toast';

/**
 * VT9 — toast rollup. When many "x completed" toasts would fire in quick
 * succession (e.g., batch geocode of 200 addresses), buffer them inside
 * a 1.5s window and emit a single "200 actions complete" toast at the end.
 *
 * Calls are keyed by `bucket`. Each bucket holds its own counter + timer.
 * Pass a `format(n)` that returns the user-facing message; gets called
 * once when the window expires.
 *
 * Example:
 *   batchedToast('geocode', () => undefined, (n) => `${n} addresses geocoded`)
 */
type Bucket = {
  count: number;
  timer: number | null;
  emit: (n: number) => string;
  type: 'success' | 'error' | 'info';
};
const buckets = new Map<string, Bucket>();

export function batchedToast(
  bucket: string,
  type: 'success' | 'error' | 'info',
  emit: (n: number) => string,
  windowMs = 1500
) {
  let b = buckets.get(bucket);
  if (!b) {
    b = { count: 0, timer: null, emit, type };
    buckets.set(bucket, b);
  } else {
    // Latest formatter wins so callers can update the message mid-flight.
    b.emit = emit;
    b.type = type;
  }
  b.count += 1;
  if (b.timer) window.clearTimeout(b.timer);
  b.timer = window.setTimeout(() => {
    const msg = b!.emit(b!.count);
    if (b!.type === 'error') toast.error(msg);
    else if (b!.type === 'info') toast(msg);
    else toast.success(msg);
    buckets.delete(bucket);
  }, windowMs);
}
