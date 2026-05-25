<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\MarketData\VendorClaimRepository;
use App\MarketData\VendorRepository;

/**
 * Vendor claim workflow — free to be listed, free to be accurate. Paid
 * promotion (vendor_promotions) is a Phase 3 concern; this is just the
 * trust-building accuracy step.
 *
 * Phase 2 approval is operator-side (an admin reviews + approves). Phase
 * 3 may add a verified-email magic-link flow.
 *
 * Routes (auth):
 *   POST /api/vendors/{id}/claims                  — submit claim
 *   GET  /api/vendors/{id}/claims                  — list claims for a vendor (admin)
 *   POST /api/vendor-claims/{id}/approve           — admin: approve
 *   POST /api/vendor-claims/{id}/reject            — admin: reject
 *   POST /api/vendors/{id}/listings                — vendor edits its own coverage (after claim approved)
 */
class VendorClaimController
{
    private VendorRepository $vendors;
    private VendorClaimRepository $claims;

    public function __construct(?VendorRepository $vendors = null, ?VendorClaimRepository $claims = null)
    {
        $this->vendors = $vendors ?? new VendorRepository();
        $this->claims  = $claims ?? new VendorClaimRepository();
    }

    public function create(Request $request): void
    {
        $vendorId = (string) $request->getParam('id');
        $vendor = $this->vendors->findById($vendorId);
        if (!$vendor) Response::error('Vendor not found', 404);
        if ($this->claims->pendingExistsFor($vendorId, $request->user['organization_id'])) {
            Response::error('Your org already has a pending claim on this vendor', 409);
        }
        $b = $request->getBody() ?? [];
        $email = trim((string) ($b['contact_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('valid contact_email required', 422);
        }
        $id = $this->claims->create(
            $vendorId,
            $request->user['organization_id'],
            $request->user['id'],
            ['email' => $email, 'phone' => $b['contact_phone'] ?? null],
            isset($b['message']) ? (string) $b['message'] : null
        );
        // Promote vendor.claim_status → pending (first claim only).
        if ($vendor['claim_status'] === 'unclaimed') {
            $this->vendors->setClaimStatus($vendorId, 'pending');
        }
        Response::success(['id' => $id], 'Claim submitted', 201);
    }

    public function listForVendor(Request $request): void
    {
        $vendorId = (string) $request->getParam('id');
        $vendor = $this->vendors->findById($vendorId);
        if (!$vendor) Response::error('Vendor not found', 404);
        // No org-scope filter — directory-side resource. Admin-only is a
        // role gate; for Phase 2 ship just role=admin in the middleware
        // layer if/when that's needed. Today everyone in an org can view.
        Response::success(['claims' => $this->claims->listForVendor($vendorId)]);
    }

    public function approve(Request $request): void { $this->decide($request, 'approved'); }
    public function reject(Request $request): void  { $this->decide($request, 'rejected'); }

    public function addListing(Request $request): void
    {
        $vendorId = (string) $request->getParam('id');
        $vendor = $this->vendors->findById($vendorId);
        if (!$vendor) Response::error('Vendor not found', 404);
        // Gate: caller's org must have an approved claim on this vendor.
        $approved = \App\Core\Database::getInstance()->fetch(
            'SELECT 1 AS one FROM vendor_claims
              WHERE vendor_id = ? AND organization_id = ? AND status = "approved" LIMIT 1',
            [$vendorId, $request->user['organization_id']]
        );
        if (!$approved) Response::error('Your org has not been approved as the claimant for this vendor', 403);

        $b = $request->getBody() ?? [];
        $cat = (string) ($b['category'] ?? '');
        if ($cat === '') Response::error('category required', 422);
        $id = $this->vendors->addListing($vendorId, [
            'category'          => $cat,
            'region'            => $b['region'] ?? null,
            'service_radius_mi' => isset($b['service_radius_mi']) ? (int) $b['service_radius_mi'] : null,
            'min_order_cents'   => isset($b['min_order_cents']) ? (int) $b['min_order_cents'] : null,
            'notes'             => $b['notes'] ?? null,
            'source'            => 'vendor_claimed',
        ]);
        Response::success(['id' => $id], 'Listing recorded');
    }

    private function decide(Request $request, string $status): void
    {
        $claimId = (string) $request->getParam('id');
        $claim = $this->claims->findById($claimId);
        if (!$claim) Response::error('Claim not found', 404);
        if ($claim['status'] !== 'pending') Response::error("Already $claim[status]", 409);
        $this->claims->decide($claimId, $status, $request->user['id']);
        // If approved, flip vendor.claim_status → claimed.
        if ($status === 'approved') {
            $this->vendors->setClaimStatus((string) $claim['vendor_id'], 'claimed');
        }
        Response::success(['id' => $claimId, 'status' => $status]);
    }
}
