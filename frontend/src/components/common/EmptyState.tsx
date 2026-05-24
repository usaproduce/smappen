import React from 'react';

/**
 * Empty-state component used across panels when there's nothing to show.
 * Variants: a big icon, a punchy title, a short subtitle, optional CTA button.
 * Saves every panel from re-inventing the same "Nothing here yet" UI.
 */
interface Props {
  icon?: React.ReactNode;
  title: string;
  subtitle?: string;
  cta?: { label: string; onClick: () => void };
  compact?: boolean;
}

export default function EmptyState({ icon, title, subtitle, cta, compact }: Props) {
  return (
    <div className={`flex flex-col items-center justify-center text-center ${compact ? 'py-6 px-3' : 'py-10 px-4'}`}>
      {icon && (
        <div className={`text-slate-300 mb-2 ${compact ? '' : 'mb-3'}`} style={{ fontSize: 0 }}>
          {icon}
        </div>
      )}
      <div className={`font-bold ${compact ? 'text-sm' : 'text-base'}`} style={{ color: '#1A1A2E' }}>
        {title}
      </div>
      {subtitle && (
        <div className={`text-slate-500 mt-1 ${compact ? 'text-xs' : 'text-sm'} max-w-xs`}>
          {subtitle}
        </div>
      )}
      {cta && (
        <button className="btn btn-primary mt-3 h-8 text-xs px-3" onClick={cta.onClick}>
          {cta.label}
        </button>
      )}
    </div>
  );
}
