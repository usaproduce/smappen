<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\MarketData\SavedVendorRepository;
use App\MarketData\VendorRepository;

/**
 * Save / follow vendors. Org-scoped.
 *
 * Routes (auth):
 *   GET    /api/saved-vendors                — list this org's saved set
 *   POST   /api/vendors/{id}/save             — save (body: { note? })
 *   DELETE /api/vendors/{id}/save             — unsave
 */
class SavedVendorController
{
    private VendorRepository $vendors;
    private SavedVendorRepository $saved;

    public function __construct(?VendorRepository $vendors = null, ?SavedVendorRepository $saved = null)
    {
        $this->vendors = $vendors ?? new VendorRepository();
        $this->saved   = $saved   ?? new SavedVendorRepository();
    }

    public function index(Request $request): void
    {
        Response::success(['saved' => $this->saved->listForOrg($request->user['organization_id'])]);
    }

    public function save(Request $request): void
    {
        $vendorId = (string) $request->getParam('id');
        if (!$this->vendors->findById($vendorId)) Response::error('Vendor not found', 404);
        $b = $request->getBody() ?? [];
        $this->saved->save(
            $request->user['organization_id'],
            $vendorId,
            $request->user['id'],
            isset($b['note']) ? (string) $b['note'] : null
        );
        Response::success([], 'Saved');
    }

    public function unsave(Request $request): void
    {
        $vendorId = (string) $request->getParam('id');
        $this->saved->unsave($request->user['organization_id'], $vendorId);
        Response::success([], 'Removed');
    }
}
