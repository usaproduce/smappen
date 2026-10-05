import { Info } from 'lucide-react';
import { STANDING } from '../../../utils/truck/wording';

export interface PermissionNoticeProps {
  /** line: one sentence. block: a panel with the longer version. */
  variant: 'line' | 'block';
}

/**
 * The standing notice (docs/truck-planner/05_FRONTEND.md 3.12, wording 6.3). The app estimates
 * demand and knows nothing about who may trade where; this notice says so wherever a spot is
 * shown. It has no close button and no "do not show again".
 */
export default function PermissionNotice({ variant }: PermissionNoticeProps) {
  if (variant === 'block') {
    return (
      <p className="flex items-start gap-2 rounded-xl border p-3 sm:p-4 text-sm font-semibold" style={{ background: 'var(--bg-panel)', borderColor: 'var(--line-soft)', color: 'var(--body)' }}>
        <Info size={16} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--ink)' }} />
        <span>{STANDING.noticeBlock}</span>
      </p>
    );
  }
  return (
    <p className="flex items-start gap-1.5 text-xs font-semibold" style={{ color: 'var(--body)' }}>
      <Info size={13} aria-hidden className="mt-px flex-none" />
      <span>{STANDING.noticeLine}</span>
    </p>
  );
}
