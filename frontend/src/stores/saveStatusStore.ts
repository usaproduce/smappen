import { create } from 'zustand';
import { markSaving, markSaved } from '../components/common/SaveStatus';

/**
 * Tracks pending mutations and the time of the last successful save so the
 * header can show "Saving…" / "Saved 12s ago" / "Couldn't save — retry".
 *
 * Mutations bump pending++ on call and pending-- on resolution. The status
 * derives from (pending, lastSavedAt, lastError):
 *   - pending > 0          → "saving"
 *   - lastError && !pending → "error"
 *   - lastSavedAt           → "saved"
 *   - otherwise             → "idle"
 *
 * Wrap any API call that mutates project state with `trackSave(promise)`.
 */
export type SaveStatus = 'idle' | 'saving' | 'saved' | 'error';

interface SaveState {
  pending: number;
  lastSavedAt: number | null;
  lastError: string | null;
  status: () => SaveStatus;
  begin: () => void;
  succeed: () => void;
  fail: (msg: string) => void;
  clearError: () => void;
}

export const useSaveStatusStore = create<SaveState>((set, get) => ({
  pending: 0,
  lastSavedAt: null,
  lastError: null,
  status: () => {
    const { pending, lastError, lastSavedAt } = get();
    if (pending > 0) return 'saving';
    if (lastError) return 'error';
    if (lastSavedAt) return 'saved';
    return 'idle';
  },
  begin: () => set((s) => ({ pending: s.pending + 1, lastError: null })),
  succeed: () => set((s) => ({ pending: Math.max(0, s.pending - 1), lastSavedAt: Date.now() })),
  fail: (msg) => set((s) => ({ pending: Math.max(0, s.pending - 1), lastError: msg })),
  clearError: () => set({ lastError: null }),
}));

/** Wraps a promise so save status updates automatically — drives both the
 * store (for richer states like error) AND the legacy markSaving/markSaved
 * indicator that's already mounted in Header. */
export async function trackSave<T>(p: Promise<T>): Promise<T> {
  const store = useSaveStatusStore.getState();
  store.begin();
  markSaving();
  try {
    const r = await p;
    useSaveStatusStore.getState().succeed();
    markSaved();
    return r;
  } catch (e: any) {
    useSaveStatusStore.getState().fail(e?.message ?? 'Save failed');
    throw e;
  }
}
