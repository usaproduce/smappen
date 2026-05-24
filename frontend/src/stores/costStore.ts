import { create } from 'zustand';

interface CostState {
  totalUsdToday: number;
  callCountToday: number;
  // Session-scoped deltas — what this tab has spent since page load.
  // Resets on refresh, so the user can isolate "what did MY latest click cost"
  // separately from the persistent daily total.
  sessionUsd: number;
  sessionCalls: number;
  setTotals: (total: number, calls: number) => void;
  trackCall: (cost: number) => void;
}

export const useCostStore = create<CostState>((set) => ({
  totalUsdToday: 0,
  callCountToday: 0,
  sessionUsd: 0,
  sessionCalls: 0,
  setTotals: (totalUsdToday, callCountToday) => set({ totalUsdToday, callCountToday }),
  trackCall: (cost) =>
    set((s) => ({
      sessionUsd: s.sessionUsd + cost,
      sessionCalls: s.sessionCalls + 1,
      // Optimistically bump the daily total too so the badge updates between
      // the 60s server polls. The poll will correct any drift.
      totalUsdToday: s.totalUsdToday + cost,
      callCountToday: s.callCountToday + 1,
    })),
}));
