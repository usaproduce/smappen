<?php
declare(strict_types=1);

namespace App\MarketData;

use App\Core\Database;
use App\Services\WebhookDispatcher;

/**
 * Lead funnel — emits supplier leads to GreenDock as outbound HMAC webhooks.
 *
 * THIS IS THE ONLY FILE ALLOWED TO INSERT INTO supplier_leads. The grep
 * test in tests/DataWall/DataWallTest.php asserts that; if any other file
 * ends up writing the outbox table, the test fails with a wall-violation
 * message pointing here. Don't relax that assertion.
 *
 * This service must also never import App\PrivateData\ or reference any
 * private-reservoir table in SQL. See src/MarketData/README.md for the
 * full rule set; the test enforces the list (so it isn't repeated here —
 * repeating it in a docblock would itself trip the test).
 *
 * The handoff is a cross-system event (spec §1a Pipe B) emitted via the
 * base's WebhookDispatcher. GreenDock is one subscriber among many
 * possible; it subscribes via the existing /api/webhooks CRUD, Carafe
 * just emits.
 */
class LeadFunnelService
{
    public const EVENT_LEAD_CREATED = 'carafe.lead.created';

    public function __construct(
        private VendorRepository $vendors,
        private WebhookDispatcher $webhooks,
    ) {}

    /**
     * Create a supplier lead from an explicit user act (one-tap quote
     * request on the comparison screen). Returns the new lead row.
     *
     * Required input: vendor + a contact email (the operator's, by
     * default — captured from the request). The comparison_id is the
     * audit trail back to the opt-in signal that produced this lead.
     */
    public function createLead(array $input): array
    {
        $vendor = $this->vendors->findById((string) $input['vendor_id']);
        if (!$vendor) {
            throw new \RuntimeException('Vendor not found');
        }
        if (empty($input['contact_email'])) {
            throw new \RuntimeException('contact_email required');
        }
        if (empty($input['organization_id'])) {
            throw new \RuntimeException('organization_id required');
        }

        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO supplier_leads
                (id, organization_id, restaurant_id, comparison_id, vendor_id, is_affiliated,
                 contact_name, contact_email, contact_phone, message, basket_json,
                 status, webhook_attempts, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "queued", 0, NOW())',
            [
                $id,
                (string) $input['organization_id'],
                $input['restaurant_id'] ?? null,
                $input['comparison_id'] ?? null,
                $vendor['id'],
                (int) $vendor['is_affiliated'],
                $input['contact_name'] ?? null,
                (string) $input['contact_email'],
                $input['contact_phone'] ?? null,
                $input['message'] ?? null,
                isset($input['basket']) ? json_encode($input['basket']) : null,
            ]
        );

        return $this->loadLead($id);
    }

    /**
     * Emit a lead to all webhook subscribers listening for the lead event.
     * Bumps webhook_attempts + records the last status. GreenDock is one
     * subscriber; this service is agnostic about who else listens.
     */
    public function emit(string $leadId): array
    {
        $lead = $this->loadLead($leadId);
        if (!$lead) {
            throw new \RuntimeException('Lead not found');
        }
        $vendor = $this->vendors->findById((string) $lead['vendor_id']);

        $payload = [
            'lead_id'         => $lead['id'],
            'organization_id' => $lead['organization_id'],
            'restaurant_id'   => $lead['restaurant_id'],
            'comparison_id'   => $lead['comparison_id'],
            'vendor' => [
                'id'             => $vendor['id'] ?? null,
                'name'           => $vendor['name'] ?? null,
                'is_affiliated'  => $vendor ? (int) $vendor['is_affiliated'] : 0,
            ],
            'contact' => [
                'name'  => $lead['contact_name'],
                'email' => $lead['contact_email'],
                'phone' => $lead['contact_phone'],
            ],
            'message'    => $lead['message'],
            'basket'     => $lead['basket_json'] ? json_decode($lead['basket_json'], true) : null,
            'created_at' => $lead['created_at'],
        ];

        $results = $this->webhooks->fanout(
            (string) $lead['organization_id'],
            self::EVENT_LEAD_CREATED,
            $payload
        );

        $bestCode = null;
        $anySuccess = false;
        foreach ($results as $r) {
            if (!empty($r['success'])) $anySuccess = true;
            if (isset($r['status_code']) && $bestCode === null) $bestCode = (int) $r['status_code'];
        }

        Database::getInstance()->query(
            'UPDATE supplier_leads
                SET webhook_attempts = webhook_attempts + 1,
                    webhook_last_at = NOW(),
                    webhook_last_code = ?,
                    status = CASE WHEN status = "queued" AND ? THEN "emitted" ELSE status END
              WHERE id = ?',
            [$bestCode, $anySuccess ? 1 : 0, $leadId]
        );

        return [
            'lead_id'    => $leadId,
            'subscribers' => count($results),
            'any_success' => $anySuccess,
            'results'    => $results,
        ];
    }

    public function loadLead(string $id): ?array
    {
        return Database::getInstance()->fetch('SELECT * FROM supplier_leads WHERE id = ?', [$id]);
    }

    public function listForOrg(string $organizationId, ?string $status = null): array
    {
        if ($status !== null) {
            return Database::getInstance()->fetchAll(
                'SELECT id, vendor_id, status, contact_email, webhook_attempts,
                        webhook_last_at, webhook_last_code, created_at
                   FROM supplier_leads WHERE organization_id = ? AND status = ?
                  ORDER BY created_at DESC LIMIT 200',
                [$organizationId, $status]
            );
        }
        return Database::getInstance()->fetchAll(
            'SELECT id, vendor_id, status, contact_email, webhook_attempts,
                    webhook_last_at, webhook_last_code, created_at
               FROM supplier_leads WHERE organization_id = ?
              ORDER BY created_at DESC LIMIT 200',
            [$organizationId]
        );
    }

    public function ack(string $leadId, ?string $externalRef): void
    {
        Database::getInstance()->query(
            'UPDATE supplier_leads SET status = "acknowledged", external_ref = ? WHERE id = ?',
            [$externalRef, $leadId]
        );
    }
}
