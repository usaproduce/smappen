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

export type AreaListGroupBy = 'none' | 'type' | 'date' | 'color';
export type AreaListFilter = 'all' | 'favorites' | 'isochrone' | 'radius' | 'manual' | 'territory';
export type MapStylePref = 'detailed' | 'clean' | 'mono' | 'dark' | 'satellite';

interface UiPrefsState {
  recentColors: string[];
  onboardingCompleted: boolean;
  shortcutsModalOpen: boolean;
  // Persisted left-panel preferences.
  areaListFilter: AreaListFilter;
  areaListGroupBy: AreaListGroupBy;
  // Persisted user-driven area ordering (per-project map of areaId → index).
  // When empty, default to creation-recency sort.
  areaOrder: Record<string, string[]>;
  mapStyle: MapStylePref;
  // VT5 — toggle: render small text labels at each polygon centroid.
  showPolygonLabels: boolean;
  // VT22-companion: badge enabled flag (off for users on slow devices).
  // Reserved for future toggle in profile settings.
  pushRecentColor: (c: string) => void;
  setOnboardingCompleted: (b: boolean) => void;
  toggleShortcutsModal: () => void;
  setAreaListFilter: (f: AreaListFilter) => void;
  setAreaListGroupBy: (g: AreaListGroupBy) => void;
  setAreaOrder: (projectId: string, ids: string[]) => void;
  clearAreaOrder: (projectId: string) => void;
  setMapStyle: (s: MapStylePref) => void;
  togglePolygonLabels: () => void;
}

export const useUiPrefsStore = create<UiPrefsState>()(
  persist(
    (set) => ({
      recentColors: [],
      onboardingCompleted: false,
      shortcutsModalOpen: false,
      areaListFilter: 'all',
      areaListGroupBy: 'none',
      areaOrder: {},
      mapStyle: 'detailed',
      showPolygonLabels: false,
      pushRecentColor: (c) =>
        set((s) => ({
          // Move to front, dedupe, cap at 5. Order = MRU.
          recentColors: [c, ...s.recentColors.filter((x) => x.toLowerCase() !== c.toLowerCase())].slice(0, 5),
        })),
      setOnboardingCompleted: (b) => set({ onboardingCompleted: b }),
      toggleShortcutsModal: () => set((s) => ({ shortcutsModalOpen: !s.shortcutsModalOpen })),
      setAreaListFilter: (f) => set({ areaListFilter: f }),
      setAreaListGroupBy: (g) => set({ areaListGroupBy: g }),
      setAreaOrder: (projectId, ids) =>
        set((s) => ({ areaOrder: { ...s.areaOrder, [projectId]: ids } })),
      clearAreaOrder: (projectId) =>
        set((s) => {
          const next = { ...s.areaOrder };
          delete next[projectId];
          return { areaOrder: next };
        }),
      setMapStyle: (m) => set({ mapStyle: m }),
      togglePolygonLabels: () => set((s) => ({ showPolygonLabels: !s.showPolygonLabels })),
    }),
    {
      name: 'smappen-ui-prefs',
      partialize: (s) => ({
        recentColors: s.recentColors,
        onboardingCompleted: s.onboardingCompleted,
        areaListFilter: s.areaListFilter,
        areaListGroupBy: s.areaListGroupBy,
        areaOrder: s.areaOrder,
        mapStyle: s.mapStyle,
        showPolygonLabels: s.showPolygonLabels,
      }),
    }
  )
);
