import React from 'react';

/**
 * Empty-state component used across panels when there's nothing to show.
 * VT25 — supports a `kind` prop that picks a small, surface-appropriate
 * SVG illustration instead of a generic icon. Each illustration is
 * inline (no extra HTTP), tuned to ~80px wide, brand-colored, and stays
 * tasteful (no AI-slop gradients).
 */
interface Props {
  icon?: React.ReactNode;
  kind?: 'areas' | 'comments' | 'businesses' | 'comparison' | 'reports' | 'field' | 'analogs';
  title: string;
  subtitle?: string;
  cta?: { label: string; onClick: () => void };
  compact?: boolean;
}

function Illustration({ kind }: { kind: NonNullable<Props['kind']> }) {
  const VIO = '#7848BB';
  const FAINT = '#EDE5F7';
  switch (kind) {
    case 'areas':
      return (
        <svg viewBox="0 0 88 64" width="88" height="64" aria-hidden="true">
          <path d="M10,40 C16,28 32,22 44,22 C58,22 76,28 80,42 C82,52 64,56 44,54 C24,52 6,52 10,40 Z"
                fill={FAINT} stroke={VIO} strokeWidth="1.4" strokeDasharray="3 3" />
          <circle cx="44" cy="36" r="4" fill={VIO} />
        </svg>
      );
    case 'comments':
      return (
        <svg viewBox="0 0 88 64" width="88" height="64" aria-hidden="true">
          <rect x="10" y="12" width="60" height="36" rx="10" fill={FAINT} stroke={VIO} strokeWidth="1.4" />
          <path d="M22,48 L26,56 L34,48 Z" fill={FAINT} stroke={VIO} strokeWidth="1.4" />
          <circle cx="30" cy="30" r="2" fill={VIO} />
          <circle cx="42" cy="30" r="2" fill={VIO} />
          <circle cx="54" cy="30" r="2" fill={VIO} />
        </svg>
      );
    case 'businesses':
      return (
        <svg viewBox="0 0 88 64" width="88" height="64" aria-hidden="true">
          <rect x="14" y="20" width="20" height="36" rx="2" fill={FAINT} stroke={VIO} strokeWidth="1.4" />
          <rect x="38" y="12" width="22" height="44" rx="2" fill={FAINT} stroke={VIO} strokeWidth="1.4" />
          <rect x="64" y="26" width="14" height="30" rx="2" fill={FAINT} stroke={VIO} strokeWidth="1.4" />
        </svg>
      );
    case 'comparison':
      return (
        <svg viewBox="0 0 88 64" width="88" height="64" aria-hidden="true">
          <rect x="10" y="14" width="32" height="36" rx="4" fill={FAINT} stroke={VIO} strokeWidth="1.4" />
          <rect x="46" y="14" width="32" height="36" rx="4" fill={FAINT} stroke={VIO} strokeWidth="1.4" />
          <line x1="44" y1="6" x2="44" y2="58" stroke={VIO} strokeWidth="1" strokeDasharray="2 2" />
        </svg>
      );
    case 'reports':
      return (
        <svg viewBox="0 0 88 64" width="88" height="64" aria-hidden="true">
          <rect x="20" y="8" width="48" height="48" rx="4" fill={FAINT} stroke={VIO} strokeWidth="1.4" />
          <line x1="28" y1="22" x2="56" y2="22" stroke={VIO} strokeWidth="1.6" />
          <line x1="28" y1="30" x2="56" y2="30" stroke={VIO} strokeWidth="1.6" />
          <line x1="28" y1="38" x2="44" y2="38" stroke={VIO} strokeWidth="1.6" />
        </svg>
      );
    case 'field':
      return (
        <svg viewBox="0 0 88 64" width="88" height="64" aria-hidden="true">
          <path d="M44,8 C36,8 30,14 30,22 C30,32 44,52 44,52 S58,32 58,22 C58,14 52,8 44,8 Z"
                fill={FAINT} stroke={VIO} strokeWidth="1.4" />
          <circle cx="44" cy="22" r="4" fill={VIO} />
        </svg>
      );
    case 'analogs':
      return (
        <svg viewBox="0 0 88 64" width="88" height="64" aria-hidden="true">
          <circle cx="44" cy="32" r="6" fill={VIO} />
          <circle cx="20" cy="20" r="4" fill="#1D9E75" />
          <circle cx="68" cy="20" r="4" fill="#378ADD" />
          <circle cx="20" cy="44" r="4" fill="#EF9F27" />
          <circle cx="68" cy="44" r="4" fill="#1D9E75" />
          <line x1="44" y1="32" x2="20" y2="20" stroke={VIO} strokeWidth="0.8" strokeDasharray="2 2" />
          <line x1="44" y1="32" x2="68" y2="20" stroke={VIO} strokeWidth="0.8" strokeDasharray="2 2" />
          <line x1="44" y1="32" x2="20" y2="44" stroke={VIO} strokeWidth="0.8" strokeDasharray="2 2" />
          <line x1="44" y1="32" x2="68" y2="44" stroke={VIO} strokeWidth="0.8" strokeDasharray="2 2" />
        </svg>
      );
  }
}

export default function EmptyState({ icon, kind, title, subtitle, cta, compact }: Props) {
  return (
    <div className={`flex flex-col items-center justify-center text-center ${compact ? 'py-6 px-3' : 'py-10 px-4'}`}>
      {/* kind takes precedence over icon — bigger and more on-brand. */}
      {kind ? (
        <div className="mb-3"><Illustration kind={kind} /></div>
      ) : icon && (
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
