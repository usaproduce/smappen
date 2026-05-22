<?php
namespace App\Services;

use App\Core\Config;
use App\Models\Organization;
use App\Models\User;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeService
{
    private StripeClient $stripe;

    public function __construct()
    {
        $secret = Config::get('STRIPE_SECRET_KEY', '');
        $this->stripe = new StripeClient($secret);
    }

    public function createCustomer(array $organization, string $ownerEmail): string
    {
        $customer = $this->stripe->customers->create([
            'email' => $ownerEmail,
            'name' => $organization['name'],
            'metadata' => ['organization_id' => $organization['id']],
        ]);
        Organization::update($organization['id'], ['stripe_customer_id' => $customer->id]);
        return $customer->id;
    }

    public function createCheckoutSession(string $organizationId, string $planName, string $successUrl, string $cancelUrl): string
    {
        $priceId = $this->priceIdFor($planName);
        if (!$priceId) throw new \InvalidArgumentException('Unknown plan: ' . $planName);

        $org = Organization::findById($organizationId);
        if (!$org) throw new \RuntimeException('Organization not found');

        $customerId = $org['stripe_customer_id'];
        if (!$customerId) {
            $owner = \App\Core\Database::getInstance()->fetch(
                "SELECT email FROM users WHERE organization_id = ? AND role = 'owner' LIMIT 1",
                [$organizationId]
            );
            $customerId = $this->createCustomer($org, $owner['email'] ?? '');
        }

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $successUrl . (str_contains($successUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'metadata' => ['organization_id' => $organizationId, 'plan' => $planName],
            'subscription_data' => ['metadata' => ['organization_id' => $organizationId, 'plan' => $planName]],
        ]);
        return $session->url;
    }

    public function createBillingPortalSession(string $organizationId, string $returnUrl): string
    {
        $org = Organization::findById($organizationId);
        if (!$org || !$org['stripe_customer_id']) throw new \RuntimeException('No Stripe customer');
        $session = $this->stripe->billingPortal->sessions->create([
            'customer' => $org['stripe_customer_id'],
            'return_url' => $returnUrl,
        ]);
        return $session->url;
    }

    public function handleWebhook(string $payload, string $sigHeader): string
    {
        $secret = Config::get('STRIPE_WEBHOOK_SECRET', '');
        $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        $type = $event->type;
        $object = $event->data->object;

        switch ($type) {
            case 'checkout.session.completed':
                $orgId = $object->metadata->organization_id ?? null;
                $plan = $object->metadata->plan ?? null;
                if ($orgId && $plan) {
                    Organization::update($orgId, [
                        'plan' => $plan,
                        'stripe_subscription_id' => $object->subscription ?? null,
                    ]);
                }
                break;

            case 'customer.subscription.updated':
                $orgId = $object->metadata->organization_id ?? null;
                if (!$orgId) {
                    $existing = Organization::findByStripeCustomerId($object->customer);
                    $orgId = $existing['id'] ?? null;
                }
                if ($orgId) {
                    $plan = $this->planFromSubscription($object);
                    Organization::update($orgId, [
                        'plan' => $plan,
                        'stripe_subscription_id' => $object->id,
                    ]);
                }
                break;

            case 'customer.subscription.deleted':
                $orgId = $object->metadata->organization_id ?? null;
                if (!$orgId) {
                    $existing = Organization::findByStripeCustomerId($object->customer);
                    $orgId = $existing['id'] ?? null;
                }
                if ($orgId) {
                    Organization::update($orgId, ['plan' => 'free', 'stripe_subscription_id' => null]);
                }
                break;

            case 'invoice.payment_failed':
                error_log('Stripe payment failed: ' . ($object->customer ?? ''));
                break;
        }
        return $type;
    }

    public function getSubscription(string $organizationId): ?array
    {
        $org = Organization::findById($organizationId);
        if (!$org || !$org['stripe_subscription_id']) return null;
        $sub = $this->stripe->subscriptions->retrieve($org['stripe_subscription_id']);
        return [
            'plan' => $org['plan'],
            'status' => $sub->status,
            'current_period_end' => $sub->current_period_end,
            'cancel_at_period_end' => (bool)$sub->cancel_at_period_end,
        ];
    }

    public function cancelSubscription(string $organizationId): void
    {
        $org = Organization::findById($organizationId);
        if (!$org || !$org['stripe_subscription_id']) throw new \RuntimeException('No active subscription');
        $this->stripe->subscriptions->update($org['stripe_subscription_id'], [
            'cancel_at_period_end' => true,
        ]);
    }

    private function priceIdFor(string $plan): ?string
    {
        return match ($plan) {
            'starter' => Config::get('STRIPE_PRICE_STARTER'),
            'pro' => Config::get('STRIPE_PRICE_PRO'),
            'business' => Config::get('STRIPE_PRICE_BUSINESS'),
            default => null,
        };
    }

    private function planFromSubscription($sub): string
    {
        $priceId = $sub->items->data[0]->price->id ?? '';
        return match ($priceId) {
            Config::get('STRIPE_PRICE_STARTER') => 'starter',
            Config::get('STRIPE_PRICE_PRO') => 'pro',
            Config::get('STRIPE_PRICE_BUSINESS') => 'business',
            default => 'free',
        };
    }
}
