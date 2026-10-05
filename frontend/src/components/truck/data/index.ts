// Truck Planner - data hooks (docs/truck-planner/05_FRONTEND.md 2.2 to 2.5).
//
// Screens get server data and model results only through these hooks. Server data lives in TanStack
// Query; the hooks add the assembly of utils/truck/assemble.ts and call the estimator through
// utils/truck/model.ts. Later packages import from this barrel and do not add files here.

export { TruckContext, regionHasData, regionRebuilding, useTruck } from './TruckContext';
export type { TruckContextValue } from './TruckContext';

export { fetchBootstrap, useBootstrap } from './useBootstrap';
export type { BootstrapData } from './useBootstrap';

export { useNow } from './useNow';
export type { TruckNow } from './useNow';

export { useSettledHow } from './useSettledHow';

export { useAddressSearchAvailable, useTruckMapsLoader } from './useMapsLoader';

export { useSpot, useSpots } from './useSpots';

export { useSimulate } from './useSimulate';
export type { SimulateRequest } from './useSimulate';

export { useSpotEstimate } from './useSpotEstimate';
export type { SpotEstimate, SpotEstimateInput } from './useSpotEstimate';

export { useDayContexts } from './useDayContexts';
export type { DayContexts, ForecastState } from './useDayContexts';

export { useDriveTimes } from './useDriveTimes';
export type { DriveTimes, DriveTimesData } from './useDriveTimes';

export { planFromRow, usePlan, usePlanForDate, usePlans } from './usePlans';
export type { PlanForDate } from './usePlans';

export { usePlanEvaluation } from './usePlanEvaluation';
export type { PlanEvaluation } from './usePlanEvaluation';

export { useServices } from './useServices';
export { refreshScout, useScout } from './useScout';
export { useSuggestDay, useSuggestWeek } from './useSuggestions';
export { useAccuracy } from './useAccuracy';
export { useSources } from './useSources';

export {
  patchChangesVectors,
  useArchiveSpot,
  useCreateSpot,
  useDeleteAllData,
  useDeleteLegOverride,
  useDeletePlan,
  useDeleteService,
  useLookupContact,
  useRefreshStaleSpots,
  useResetOverrides,
  useSaveLead,
  useSaveLeadAsSpot,
  useSaveLegOverride,
  useSaveOverrides,
  useSavePlan,
  useSaveProfile,
  useSaveService,
  useUpdateSpot,
} from './mutations';
export type { LegOverrideVariables, SavePlanVariables, SaveServiceVariables } from './mutations';

export { GC, STALE, truckRetry } from './queryPolicy';
