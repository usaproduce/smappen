<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\CogsBenchmarkService;

/**
 * Admin endpoints over the COGS benchmark.
 *
 * - GET /api/admin/cogs/health     → operator dashboard summary
 * - GET /api/admin/cogs/trace      → drill into one (key, region) price
 * - GET /api/admin/cogs/unmatched  → top unmatched commodities, with paging
 *
 * Mounted under requireRole(['admin','owner']) in config/routes.php so
 * non-admin operators get a 403.
 */
class CogsAdminController
{
    private CogsBenchmarkService $svc;

    public function __construct(?CogsBenchmarkService $svc = null)
    {
        $this->svc = $svc ?? new CogsBenchmarkService();
    }

    public function health(Request $request): void
    {
        Response::success($this->svc->healthSummary());
    }

    public function trace(Request $request): void
    {
        $key    = trim((string) ($request->getQuery('key') ?? ''));
        $region = $request->getQuery('region');
        if ($key === '') {
            Response::error('Missing required query: key', 400);
        }
        $regionStr = (is_string($region) && $region !== '') ? $region : null;
        Response::success($this->svc->lookupTrace($key, $regionStr));
    }
}
