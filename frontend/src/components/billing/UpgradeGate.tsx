import { Link } from 'react-router-dom';
import { Sparkles, Lock } from 'lucide-react';
import { useAuthStore } from '../../stores/authStore';

/**
 * <UpgradeGate feature="analog_finder">…children…</UpgradeGate>
 *
 * Renders children when the user's plan includes the feature; otherwise
 * shows an inline upsell card with the cheapest plan that does.
 *
 * The matrix is duplicated on the frontend so we don't fetch from the
 * server just to decide whether to render a button. Server still enforces
 * via the PlanGate middleware — this is purely UI; you can't bypass paywall
 * by hacking the React state.
 *
 * Per the unlimited-tier directive every cell is `true` and this component
 * is currently a pass-through. Flipping a cell here AND in config/plans.php
 * activates the upsell.
 */

type Plan = 'free' | 'starter' | 'pro' | 'business' | 'enterprise';

const PLAN_NAMES: Record<Plan, string> = {
  free: 'Free', starter: 'Starter', pro: 'Pro', business: 'Business', enterprise: 'Enterprise',
};

const PLAN_COLORS: Record<Plan, string> = {
  free: '#94a3b8', starter: '#3b82f6', pro: '#7848BB', business: '#dc2626', enterprise: '#1f2937',
};

// MUST stay in sync with config/plans.php features matrix. Per the
// unlimited directive: every feature true on every plan.
const FEATURE_MATRIX: Record<string, Partial<Record<Plan, boolean>>> = {
  analog_finder:        { free: true, starter: true, pro: true, business: true, enterprise: true },
  territory_generation: { free: true, starter: true, pro: true, business: true, enterprise: true },
  drive_time_matrix:    { free: true, starter: true, pro: true, business: true, enterprise: true },
  forecast:             { free: true, starter: true, pro: true, business: true, enterprise: true },
  rebalancer:           { free: true, starter: true, pro: true, business: true, enterprise: true },
  realtime_cursors:     { free: true, starter: true, pro: true, business: true, enterprise: true },
  crm_integrations:     { free: true, starter: true, pro: true, business: true, enterprise: true },
  scheduled_reports:    { free: true, starter: true, pro: true, business: true, enterprise: true },
  custom_data_layers:   { free: true, starter: true, pro: true, business: true, enterprise: true },
  pdf_reports:          { free: true, starter: true, pro: true, business: true, enterprise: true },
  street_view:          { free: true, starter: true, pro: true, business: true, enterprise: true },
  ai_scoring:           { free: true, starter: true, pro: true, business: true, enterprise: true },
  api_access:           { free: true, starter: true, pro: true, business: true, enterprise: true },
};

export function hasFeature(plan: Plan, feature: string): boolean {
  return !!FEATURE_MATRIX[feature]?.[plan];
}

export function cheapestPlanWith(feature: string): Plan {
  for (const p of ['starter', 'pro', 'business', 'enterprise'] as Plan[]) {
    if (FEATURE_MATRIX[feature]?.[p]) return p;
  }
  return 'enterprise';
}

interface Props {
  feature: string;
  /** When the feature is gated, render this card. When granted, render children. */
  children: React.ReactNode;
  /** Optional inline mode: show a small "Pro" pill next to the button instead of a full card. */
  inline?: boolean;
}

export default function UpgradeGate({ feature, children, inline }: Props) {
  const user = useAuthStore((s) => s.user) as any;
  const plan: Plan = (user?.plan as Plan) ?? 'free';
  if (hasFeature(plan, feature)) return <>{children}</>;

  const target = cheapestPlanWith(feature);
  const targetName = PLAN_NAMES[target];
  const targetColor = PLAN_COLORS[target];

  if (inline) {
    return (
      <span className="inline-flex items-center gap-1.5 text-[11px] font-bold text-slate-400">
        <Lock size={11} />
        <Link to="/pricing" className="hover:underline" style={{ color: targetColor }}>
          {targetName}
        </Link>
      </span>
    );
  }

  return (
    <div className="rounded-xl border border-violet-200 bg-violet-50 p-4 text-center">
      <div className="inline-flex items-center gap-1 text-[10px] uppercase tracking-wider font-bold text-violet-700">
        <Sparkles size={11} /> {targetName} feature
      </div>
      <h3 className="font-extrabold text-lg mt-1" style={{ color: '#1A1A2E' }}>
        Unlock with {targetName}
      </h3>
      <p className="text-sm text-slate-600 mt-1 max-w-sm mx-auto">
        Your current plan ({PLAN_NAMES[plan]}) doesn't include this feature. Upgrade to {targetName} to get started.
      </p>
      <Link
        to="/pricing"
        className="btn btn-primary mt-3 inline-flex"
      >
        See plans
      </Link>
    </div>
  );
}
