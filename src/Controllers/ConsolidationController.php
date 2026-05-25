<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\MarketData\VendorRepository;
use App\Services\OrderConsolidationService;
use App\SharedRef\CogsBenchmarkRepository;

/**
 * Order consolidation view — spec §6.4.
 *
 * Routes (auth):
 *   POST /api/vendors/consolidate    — body: { basket: [...], region? }
 */
class ConsolidationController
{
    private OrderConsolidationService $svc;

    public function __construct(?OrderConsolidationService $svc = null)
    {
        $this->svc = $svc ?? new OrderConsolidationService(new VendorRepository(), new CogsBenchmarkRepository());
    }

    public function compare(Request $request): void
    {
        $b = $request->getBody() ?? [];
        $basket = is_array($b['basket'] ?? null) ? $b['basket'] : [];
        if (empty($basket)) Response::error('basket required', 422);
        $region = isset($b['region']) ? (string) $b['region'] : null;
        Response::success($this->svc->compare($basket, $region));
    }
}
