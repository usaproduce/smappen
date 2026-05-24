import { useEffect, useState } from 'react';
import { Check, Cloud } from 'lucide-react';

/**
 * OP6 — auto-save status pill. Components that auto-save can call the
 * exported `markSaving` / `markSaved` functions; this pill subscribes to
 * the shared module-level state and shows "Saving…" / "Saved" with a soft
 * fade. Fades out after 2s when saved.
 */

type Status = 'idle' | 'saving' | 'saved';
const listeners: Set<(s: Status) => void> = new Set();
let current: Status = 'idle';
let savedHideTimer: number | null = null;

export function markSaving() {
  current = 'saving';
  listeners.forEach((fn) => fn(current));
}
export function markSaved() {
  current = 'saved';
  listeners.forEach((fn) => fn(current));
  if (savedHideTimer) window.clearTimeout(savedHideTimer);
  savedHideTimer = window.setTimeout(() => {
    current = 'idle';
    listeners.forEach((fn) => fn(current));
  }, 2000);
}

export default function SaveStatus() {
  const [s, setS] = useState<Status>(current);
  useEffect(() => {
    listeners.add(setS);
    return () => { listeners.delete(setS); };
  }, []);
  if (s === 'idle') return null;
  return (
    <span className={`inline-flex items-center gap-1 text-[11px] font-semibold transition-opacity ${
      s === 'saving' ? 'text-slate-500' : 'text-emerald-600'
    }`}>
      {s === 'saving' ? (
        <><Cloud size={11} /> Saving…</>
      ) : (
        <><Check size={11} /> Saved</>
      )}
    </span>
  );
}
