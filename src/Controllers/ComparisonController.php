<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\MarketData\VendorRepository;
use App\Services\VendorComparisonService;
use App\SharedRef\CogsBenchmarkRepository;

/**
 * Vendor comparison — honest ranking surface.
 *
 * Routes (auth):
 *   POST /api/vendors/compare           — body: { category, region?, basket?: [...] }
 */
class ComparisonController
{
    private VendorComparisonService $svc;

    public function __construct(?VendorComparisonService $svc = null)
    {
        $this->svc = $svc ?? new VendorComparisonService(new VendorRepository(), new CogsBenchmarkRepository());
    }

    public function compare(Request $request): void
    {
        $b = $request->getBody() ?? [];
        $category = trim((string) ($b['category'] ?? ''));
        if ($category === '') Response::error('category required', 422);
        $region = isset($b['region']) ? (string) $b['region'] : null;
        $basket = is_array($b['basket'] ?? null) ? $b['basket'] : [];
        Response::success($this->svc->compare($category, $region, $basket));
    }
}
