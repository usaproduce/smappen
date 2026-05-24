import { create } from 'zustand';

/**
 * Local-only undo/redo stack. Each action records enough state to reverse
 * itself client-side; we don't need a server endpoint because every reversal
 * dispatches the same CRUD calls the user would have made manually.
 *
 *  - max 20 entries; older ones drop off the bottom
 *  - `do()` pushes an action and clears the redo stack (standard linear history)
 *  - `undo()` runs the action's reverse() and moves it to the redo stack
 *  - `redo()` runs the action's forward() and moves it back to the undo stack
 *
 * Actions carry both their forward and reverse closures (built when the
 * mutating UI fires), so the store has no dependency on the API layer.
 */

export interface UndoAction {
  /** Short human-readable label for tooltips (e.g. "Delete Territory NW"). */
  label: string;
  /** Reverse the mutation. Called by undo(). */
  reverse: () => Promise<void> | void;
  /** Re-apply the mutation. Called by redo(). */
  forward: () => Promise<void> | void;
}

interface UndoState {
  past: UndoAction[];
  future: UndoAction[];
  busy: boolean;
  do: (action: UndoAction) => void;
  undo: () => Promise<void>;
  redo: () => Promise<void>;
  clear: () => void;
  canUndo: () => boolean;
  canRedo: () => boolean;
}

const MAX_HISTORY = 20;

export const useUndoStore = create<UndoState>((set, get) => ({
  past: [],
  future: [],
  busy: false,
  do: (action) => {
    set((s) => ({
      past: [...s.past.slice(-MAX_HISTORY + 1), action],
      future: [], // any new mutation clears the redo trail — standard linear history
    }));
  },
  async undo() {
    const { past, busy } = get();
    if (busy || past.length === 0) return;
    const action = past[past.length - 1];
    set({ busy: true });
    try {
      await action.reverse();
      set((s) => ({
        past: s.past.slice(0, -1),
        future: [...s.future, action],
        busy: false,
      }));
    } catch (e) {
      set({ busy: false });
      throw e;
    }
  },
  async redo() {
    const { future, busy } = get();
    if (busy || future.length === 0) return;
    const action = future[future.length - 1];
    set({ busy: true });
    try {
      await action.forward();
      set((s) => ({
        future: s.future.slice(0, -1),
        past: [...s.past, action],
        busy: false,
      }));
    } catch (e) {
      set({ busy: false });
      throw e;
    }
  },
  clear: () => set({ past: [], future: [] }),
  canUndo: () => get().past.length > 0 && !get().busy,
  canRedo: () => get().future.length > 0 && !get().busy,
}));
