// Truck Planner UI kit (docs/truck-planner/05_FRONTEND.md section 3). Built once; screens do not
// re-implement any of it.
//
// The kit is where the product's honesty and the owner's taste are enforced by construction:
// RangeValue takes only an Estimate, numbers people decide on are weight 600 or more in tabular
// figures, text is ink or body colour, cards are flat and bordered, and every colour is a CSS
// variable. Pure logic lives in ./kit and in utils/truck; these components are thin shells.

export { default as RangeValue } from './RangeValue';
export type { RangeValueProps } from './RangeValue';

export { default as ConfidenceChip } from './ConfidenceChip';
export type { ConfidenceChipProps } from './ConfidenceChip';

export { default as WhyDrawer } from './WhyDrawer';
export type { WhyDrawerProps, WhySubject } from './WhyDrawer';

export { default as NumberField } from './NumberField';
export type { NumberFieldProps } from './NumberField';

export { default as MoneyField } from './MoneyField';
export type { MoneyFieldProps } from './MoneyField';

export { default as TimeField } from './TimeField';
export type { TimeFieldProps } from './TimeField';

export { default as Modal } from './Modal';
export type { ModalProps } from './Modal';

export { default as Sheet } from './Sheet';
export type { SheetProps } from './Sheet';

export { default as Tabs, TabPanel } from './Tabs';
export type { TabItem, TabPanelProps, TabsProps } from './Tabs';

export { default as Toggle } from './Toggle';
export type { ToggleProps } from './Toggle';

export { default as Field, fieldControlProps } from './Field';
export type { FieldControlProps, FieldProps } from './Field';

export { default as DateStepper } from './DateStepper';
export type { DateStepperProps } from './DateStepper';

export { default as DataTable } from './DataTable';
export type { Column, DataTableProps } from './DataTable';

export { default as HourBars } from './HourBars';
export type { HourBarsProps } from './HourBars';

export { default as WeekStrip } from './WeekStrip';
export type { WeekStripProps } from './WeekStrip';

export { default as Timeline } from './Timeline';
export type { TimelineProps } from './Timeline';

export { default as StatRow, StatList } from './StatRow';
export type { StatRowProps } from './StatRow';

export { default as EmptyState } from './EmptyState';
export type { EmptyStateAction, EmptyStateProps } from './EmptyState';

export { default as QueryError } from './QueryError';
export type { QueryErrorProps } from './QueryError';

export { SkeletonCard, SkeletonChart, SkeletonRows } from './Skeletons';
export type { SkeletonCardProps, SkeletonChartProps, SkeletonRowsProps } from './Skeletons';

export { default as PermissionNotice } from './PermissionNotice';
export type { PermissionNoticeProps } from './PermissionNotice';

export { default as SourceLine } from './SourceLine';
export type { SourceKind, SourceLineProps } from './SourceLine';

export { default as SeedTag } from './SeedTag';
export type { SeedTagProps } from './SeedTag';

export { default as WeatherChip } from './WeatherChip';
export type { WeatherChipProps } from './WeatherChip';

export { default as HolidayChip } from './HolidayChip';
export type { HolidayChipProps } from './HolidayChip';

export { default as WarningList } from './WarningList';
export type { WarningListProps } from './WarningList';

export { default as OpenInMaps } from './OpenInMaps';
export type { OpenInMapsProps } from './OpenInMaps';

export { nextSort, sortRows, tabDomIds } from './kit';
