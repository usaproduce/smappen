<?php
namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\PlanLimits;
use App\Services\StripeService;

class BillingController
{
    public function createCheckout(Request $request): void
    {
        if (($request->user['role'] ?? '') !== 'owner') {
            Response::error('Only org owners can change plans', 403);
        }
        $body = $request->getBody() ?? [];
        $plan = $body['plan'] ?? '';
        if (!in_array($plan, ['starter', 'pro', 'business'])) Response::error('Invalid plan');

        $frontend = Config::get('FRONTEND_URL', 'http://localhost:5173');
        try {
            $url = (new StripeService())->createCheckoutSession(
                $request->user['organization_id'],
                $plan,
                $frontend . '/settings/billing?success=1',
                $frontend . '/pricing'
            );
            Response::success(['checkout_url' => $url]);
        } catch (\Throwable $e) {
            Response::error('Stripe checkout failed: ' . $e->getMessage(), 502);
        }
    }

    public function webhook(Request $request): void
    {
        $payload = file_get_contents('php://input');
        $sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        try {
            $type = (new StripeService())->handleWebhook($payload, $sig);
            Response::success(['handled' => $type]);
        } catch (\Throwable $e) {
            error_log('Stripe webhook error: ' . $e->getMessage());
            Response::error('Webhook error', 400);
        }
    }

    public function subscription(Request $request): void
    {
        $plan = $request->user['plan'] ?? 'free';
        $limits = PlanLimits::getLimits($plan);
        try {
            $sub = (new StripeService())->getSubscription($request->user['organization_id']);
        } catch (\Throwable $e) {
            $sub = null;
        }
        $usage = [
            'isochrones_remaining_today' => PlanLimits::getRemainingUsage($request->user['id'], 'max_isochrones_per_day', $plan),
            'poi_searches_remaining_today' => PlanLimits::getRemainingUsage($request->user['id'], 'max_poi_searches_per_day', $plan),
        ];
        Response::success([
            'plan' => $plan,
            'limits' => $limits,
            'usage' => $usage,
            'subscription' => $sub,
        ]);
    }

    public function portal(Request $request): void
    {
        if (($request->user['role'] ?? '') !== 'owner') {
            Response::error('Only owners can access billing portal', 403);
        }
        $frontend = Config::get('FRONTEND_URL', 'http://localhost:5173');
        try {
            $url = (new StripeService())->createBillingPortalSession(
                $request->user['organization_id'],
                $frontend . '/settings/billing'
            );
            Response::success(['portal_url' => $url]);
        } catch (\Throwable $e) {
            Response::error('Portal error: ' . $e->getMessage(), 502);
        }
    }

    public function cancel(Request $request): void
    {
        if (($request->user['role'] ?? '') !== 'owner') {
            Response::error('Only owners can cancel', 403);
        }
        try {
            (new StripeService())->cancelSubscription($request->user['organization_id']);
            Response::success([], 'Subscription will cancel at period end');
        } catch (\Throwable $e) {
            Response::error('Cancel failed: ' . $e->getMessage(), 502);
        }
    }
}
