<?php
declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Response;
use App\Core\PlanLimits;

/**
 * Plan-feature gate. Wrap any route that needs a plan check:
 *
 *   $r->post('/api/areas/{id}/analogs', [AnalogController::class, 'find'],
 *       [Middleware::auth(), Middleware::rateLimit('analog_finder', 30), PlanGate::feature('analog_finder')]);
 *
 * When the feature is enabled for the user's plan, the middleware no-ops.
 * When disabled, it returns 403 + `{upgrade_required: true, current_plan,
 * required_plan}` so the frontend's <UpgradeGate> can render a polite
 * upsell card instead of a generic error.
 *
 * Per the unlimited-tier directive every flag is currently true; this
 * middleware exists so flipping a flag in `config/plans.php` activates
 * enforcement everywhere at once.
 */
class PlanGate
{
    /** Returns a callable suitable for the router's middleware array. */
    public static function feature(string $featureName): callable
    {
        return function ($request) use ($featureName) {
            $plan = $request->user['plan'] ?? 'free';
            $hasFeature = PlanLimits::getLimit($plan, $featureName);
            // Treat boolean true OR numeric -1 (unlimited) as "allowed."
            if ($hasFeature === true || $hasFeature === -1) return true;
            if (is_numeric($hasFeature) && (int)$hasFeature > 0) return true;

            // Find the cheapest plan that DOES include the feature so we
            // can recommend an upgrade target specifically, not just "any
            // higher tier."
            $required = self::cheapestPlanWith($featureName);
            Response::json([
                'success' => false,
                'error' => 'This feature requires a higher plan.',
                'upgrade_required' => true,
                'current_plan' => $plan,
                'required_plan' => $required,
                'feature' => $featureName,
            ], 403);
            exit;
        };
    }

    /** Numeric-limit variant: enforces "you have N / 10 used today" style caps. */
    public static function quota(string $limitName, callable $usageProvider): callable
    {
        return function ($request) use ($limitName, $usageProvider) {
            $plan = $request->user['plan'] ?? 'free';
            $cap = PlanLimits::getLimit($plan, $limitName);
            if ($cap === -1) return true; // unlimited
            $used = (int)$usageProvider($request);
            if ($used >= (int)$cap) {
                $required = self::cheapestPlanWith($limitName);
                Response::json([
                    'success' => false,
                    'error' => "You've hit your $limitName limit for this plan.",
                    'upgrade_required' => true,
                    'current_plan' => $plan,
                    'required_plan' => $required,
                    'limit' => (int)$cap,
                    'used' => $used,
                ], 402); // Payment Required is a real status code
                exit;
            }
            return true;
        };
    }

    private static function cheapestPlanWith(string $featureName): string
    {
        // Walk plan order from cheapest to most expensive and return the
        // first that has the feature enabled.
        foreach (['starter', 'pro', 'business', 'enterprise'] as $candidate) {
            $v = PlanLimits::getLimit($candidate, $featureName);
            if ($v === true || $v === -1 || (is_numeric($v) && (int)$v > 0)) {
                return $candidate;
            }
        }
        return 'enterprise';
    }
}
