import { create } from 'zustand';
import { persist } from 'zustand/middleware';

/**
 * Lightweight per-user UI preferences that don't belong on the server.
 * Persisted to localStorage. Currently:
 *
 *  - recentColors: last 5 colors the user picked, for the area color menu
 *  - onboardingCompleted: tour gate flag
 *  - shortcutsModalOpen: ephemeral, not persisted (left out of partialize)
 */

interface UiPrefsState {
  recentColors: string[];
  onboardingCompleted: boolean;
  shortcutsModalOpen: boolean;
  pushRecentColor: (c: string) => void;
  setOnboardingCompleted: (b: boolean) => void;
  toggleShortcutsModal: () => void;
}

export const useUiPrefsStore = create<UiPrefsState>()(
  persist(
    (set) => ({
      recentColors: [],
      onboardingCompleted: false,
      shortcutsModalOpen: false,
      pushRecentColor: (c) =>
        set((s) => ({
          // Move to front, dedupe, cap at 5. Order = MRU.
          recentColors: [c, ...s.recentColors.filter((x) => x.toLowerCase() !== c.toLowerCase())].slice(0, 5),
        })),
      setOnboardingCompleted: (b) => set({ onboardingCompleted: b }),
      toggleShortcutsModal: () => set((s) => ({ shortcutsModalOpen: !s.shortcutsModalOpen })),
    }),
    {
      name: 'smappen-ui-prefs',
      partialize: (s) => ({
        recentColors: s.recentColors,
        onboardingCompleted: s.onboardingCompleted,
      }),
    }
  )
);
