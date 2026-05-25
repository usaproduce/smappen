<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\MarketData\ComparisonRequestRepository;
use App\MarketData\LeadFunnelService;
use App\MarketData\VendorRepository;
use App\Services\WebhookDispatcher;

/**
 * One-tap quote / switch — spec §6.3. Operator clicks Request Quote on a
 * vendor row; we record the comparison_request as the opt-in audit trail,
 * create a supplier_lead via LeadFunnelService, and emit it.
 *
 * Routes (auth):
 *   POST /api/vendors/compare/log        — log a comparison run (frontend
 *                                          calls this just before showing
 *                                          results, so the opt-in signal
 *                                          is captured even if the user
 *                                          doesn't end up requesting a quote)
 *   POST /api/leads                      — body: { vendor_id, contact_email,
 *                                                  comparison_id?, restaurant_id?,
 *                                                  basket?, message? }
 *   GET  /api/leads                      — list this org's leads
 *   POST /api/leads/{id}/emit            — manually re-emit (idempotent on
 *                                          subscriber side via id)
 *   POST /api/leads/webhook/ack          — public (HMAC-protected upstream)
 *                                          callback for GreenDock to mark
 *                                          a lead acknowledged
 */
class LeadController
{
    private ComparisonRequestRepository $comparisons;
    private LeadFunnelService $funnel;
    private VendorRepository $vendors;

    public function __construct(
        ?ComparisonRequestRepository $comparisons = null,
        ?LeadFunnelService $funnel = null,
        ?VendorRepository $vendors = null,
    ) {
        $this->vendors = $vendors ?? new VendorRepository();
        $this->comparisons = $comparisons ?? new ComparisonRequestRepository();
        $this->funnel = $funnel ?? new LeadFunnelService($this->vendors, new WebhookDispatcher());
    }

    public function logComparison(Request $request): void
    {
        $b = $request->getBody() ?? [];
        $category = trim((string) ($b['category'] ?? ''));
        if ($category === '') Response::error('category required', 422);
        $id = $this->comparisons->create(
            $request->user['organization_id'],
            isset($b['restaurant_id']) ? (string) $b['restaurant_id'] : null,
            $category,
            isset($b['region']) ? (string) $b['region'] : null,
            is_array($b['basket'] ?? null) ? $b['basket'] : [],
            is_array($b['vendor_ids'] ?? null) ? $b['vendor_ids'] : [],
        );
        Response::success(['comparison_id' => $id], 'Comparison logged', 201);
    }

    public function create(Request $request): void
    {
        $b = $request->getBody() ?? [];
        $vendorId = trim((string) ($b['vendor_id'] ?? ''));
        $email    = trim((string) ($b['contact_email'] ?? ''));
        if ($vendorId === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('vendor_id and a valid contact_email required', 422);
        }
        try {
            $lead = $this->funnel->createLead([
                'organization_id' => $request->user['organization_id'],
                'restaurant_id'   => $b['restaurant_id'] ?? null,
                'comparison_id'   => $b['comparison_id'] ?? null,
                'vendor_id'       => $vendorId,
                'contact_name'    => $b['contact_name'] ?? null,
                'contact_email'   => $email,
                'contact_phone'   => $b['contact_phone'] ?? null,
                'message'         => $b['message'] ?? null,
                'basket'          => is_array($b['basket'] ?? null) ? $b['basket'] : null,
            ]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        }
        // Best-effort emit. Webhook failure doesn't mean the lead failed —
        // the row's there, the cron retry path (next_retry_at) catches it.
        try {
            $this->funnel->emit((string) $lead['id']);
        } catch (\Throwable $e) {
            error_log('[lead] emit failed: ' . $e->getMessage());
        }
        Response::success(['lead_id' => $lead['id'], 'status' => 'created'], 'Lead created', 201);
    }

    public function index(Request $request): void
    {
        $status = $request->getQuery('status');
        $rows = $this->funnel->listForOrg(
            $request->user['organization_id'],
            $status ? (string) $status : null
        );
        Response::success(['leads' => $rows]);
    }

    public function emit(Request $request): void
    {
        $leadId = (string) $request->getParam('id');
        $lead = $this->funnel->loadLead($leadId);
        if (!$lead || $lead['organization_id'] !== $request->user['organization_id']) {
            Response::error('Lead not found', 404);
        }
        $result = $this->funnel->emit($leadId);
        Response::success($result);
    }
}
