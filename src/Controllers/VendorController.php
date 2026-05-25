<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\MarketData\VendorRepository;

/**
 * Vendor directory — browse + show. Public-side surface (uses MarketData
 * namespace; does not touch any restaurant private data).
 *
 * Routes (auth — the directory is browseable by any logged-in restaurant
 * org. Vendor claim/edit lives in VendorClaimController):
 *   GET /api/vendors                     — search w/ filters: category, region, q, claim_status
 *   GET /api/vendors/{id}                — one vendor + its listings
 */
class VendorController
{
    private VendorRepository $repo;

    public function __construct(?VendorRepository $repo = null)
    {
        $this->repo = $repo ?? new VendorRepository();
    }

    public function index(Request $request): void
    {
        $rows = $this->repo->search([
            'category'     => $request->getQuery('category'),
            'region'       => $request->getQuery('region'),
            'q'            => $request->getQuery('q'),
            'claim_status' => $request->getQuery('claim_status'),
        ]);
        Response::success(['vendors' => $rows]);
    }

    public function show(Request $request): void
    {
        $id = (string) $request->getParam('id');
        $vendor = $this->repo->findById($id);
        if (!$vendor) Response::error('Vendor not found', 404);
        $vendor['listings'] = $this->repo->listingsFor($id);
        Response::success(['vendor' => $vendor]);
    }
}
