// Shared primitives used by every tab in the advanced panel. Extracted from
// the original 700-line AdvancedPanel.tsx so each tab can live in its own
// file without re-declaring these helpers.

import React from 'react';

export function Spinner({ size = 'sm' }: { size?: 'sm' | 'lg' }) {
  // Use the global .spinner class so theming + sizing are consistent across
  // every place we show inline progress. Earlier inline tailwind border-spinners
  // had drifted to slightly different colors/sizes.
  return <span className={size === 'lg' ? 'spinner spinner-lg' : 'spinner'} style={{ width: size === 'sm' ? 14 : undefined, height: size === 'sm' ? 14 : undefined }} />;
}

export function Empty({ msg }: { msg: string }) {
  return <div className="p-6 text-sm text-slate-500 text-center">{msg}</div>;
}

export function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1 block">{label}</span>
      {children}
    </label>
  );
}

/** Skeleton loader — replaces the spinner+blank flicker pattern. */
export function SkeletonRow({ height = 32 }: { height?: number }) {
  return (
    <div className="animate-pulse bg-slate-100 rounded mb-1.5" style={{ height }} />
  );
}
