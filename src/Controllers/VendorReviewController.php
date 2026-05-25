<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\MarketData\VendorRepository;
use App\MarketData\VendorReviewRepository;
use App\Services\VendorReviewService;

/**
 * Vendor reviews — verified-operator-only submissions, vendor responses,
 * aggregation read.
 *
 * Routes (auth):
 *   GET  /api/vendors/{id}/reviews                    — list
 *   POST /api/vendors/{id}/reviews                    — submit/update (one per org per vendor)
 *   GET  /api/vendors/{id}/reviews/aggregate          — aggregate scores
 *   POST /api/vendor-reviews/{id}/respond             — vendor-side public reply (claimed orgs only)
 */
class VendorReviewController
{
    private VendorRepository $vendors;
    private VendorReviewRepository $reviews;
    private VendorReviewService $svc;

    public function __construct(
        ?VendorRepository $vendors = null,
        ?VendorReviewRepository $reviews = null,
        ?VendorReviewService $svc = null,
    ) {
        $this->vendors = $vendors ?? new VendorRepository();
        $this->reviews = $reviews ?? new VendorReviewRepository();
        $this->svc = $svc ?? new VendorReviewService($this->reviews);
    }

    public function list(Request $request): void
    {
        $vendorId = (string) $request->getParam('id');
        $vendor = $this->vendors->findById($vendorId);
        if (!$vendor) Response::error('Vendor not found', 404);
        $rows = $this->reviews->listForVendor($vendorId, 50);
        $mine = $this->reviews->findByOrgVendor($request->user['organization_id'], $vendorId);
        Response::success([
            'reviews'   => $rows,
            'my_review' => $mine,
        ]);
    }

    public function submit(Request $request): void
    {
        $vendorId = (string) $request->getParam('id');
        $vendor = $this->vendors->findById($vendorId);
        if (!$vendor) Response::error('Vendor not found', 404);
        $b = $request->getBody() ?? [];
        try {
            $res = $this->svc->submit(
                $vendorId,
                $request->user['organization_id'],
                $request->user['id'],
                $b
            );
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        }
        Response::success($res, 'Review saved', 201);
    }

    public function aggregate(Request $request): void
    {
        $vendorId = (string) $request->getParam('id');
        if (!$this->vendors->findById($vendorId)) Response::error('Vendor not found', 404);
        Response::success($this->reviews->aggregateForVendor($vendorId));
    }

    public function respond(Request $request): void
    {
        $reviewId = (string) $request->getParam('id');
        $review = $this->reviews->findById($reviewId);
        if (!$review) Response::error('Review not found', 404);

        // The responder's org must hold an approved claim on this vendor.
        $approved = Database::getInstance()->fetch(
            'SELECT 1 AS one FROM vendor_claims
              WHERE vendor_id = ? AND organization_id = ? AND status = "approved" LIMIT 1',
            [$review['vendor_id'], $request->user['organization_id']]
        );
        if (!$approved) Response::error('Only an approved vendor-claim holder may respond to reviews', 403);

        $b = $request->getBody() ?? [];
        $body = trim((string) ($b['body'] ?? ''));
        if ($body === '') Response::error('body required', 422);

        Database::getInstance()->query(
            'INSERT INTO vendor_review_responses
                (id, review_id, vendor_id, responder_org_id, responder_user_id, body, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE body = VALUES(body), updated_at = NOW()',
            [
                Database::uuid(), $reviewId, $review['vendor_id'],
                $request->user['organization_id'], $request->user['id'], $body,
            ]
        );
        Response::success([], 'Response posted');
    }
}
