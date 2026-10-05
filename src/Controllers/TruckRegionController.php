<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Services\CaptureService;
use App\TruckPlanner\Services\RegionService;

/**
 * Truck Planner: regions and the cell pack (docs/truck-planner/04_BACKEND.md 4.6, 03_DATA.md 11.1).
 *
 * Routes (04_BACKEND.md section 3):
 *   GET /api/truck/regions  ->  index
 *   GET /api/truck/regions/{region_id}/pack/{dataset_version}  ->  pack
 *
 * Neither needs a truck. The pack is the one answer of Truck Planner that is not JSON: the gzip-compressed
 * binary of a dataset version, exactly as the loader stored it. Its address holds the version, so the
 * answer never changes and is cached for good by the browser.
 */
class TruckRegionController extends TruckBaseController
{
    /** Every region with its live dataset version, whether it can be used, and where its pack is. */
    public function index(Request $request): void
    {
        $this->run(function () use ($request): void {
            $this->orgId($request);
            $this->ok(['regions' => (new RegionService())->list()]);
        });
    }

    /**
     * The cell pack of one dataset version.
     *
     * 404 for a version that does not exist or is not `ready`. 409 when the version was built with other
     * model constants than this server has. 304 when the browser already holds it. Otherwise the bytes,
     * gzip-encoded when the client accepts that.
     */
    public function pack(Request $request): void
    {
        $this->run(function () use ($request): void {
            $this->orgId($request);
            $regionId = (string) $request->getParam('region_id', '');
            $version = (string) $request->getParam('dataset_version', '');

            // 1. the ledger row, never the blob yet
            $repository = new RegionRepository();
            $meta = null;
            if (preg_match('/^[a-z0-9]{1,24}$/', $regionId) === 1 && preg_match('/^[a-z0-9-]{1,48}$/', $version) === 1) {
                $meta = $repository->packMeta($regionId, $version);
            }
            if ($meta === null || $meta['load_state'] !== 'ready' || $meta['pack_sha256'] === null) {
                Response::error('Not found', 404);
            }
            if (!(new RegionService($repository))->kernelMatches($meta['kernel'])) {
                Response::error(CaptureService::BUILD_MISMATCH, 409);
            }

            // 2. the validator: one per encoding. A browser that holds the pack gets 304 and no blob is read.
            $gzip = stripos((string) $request->getHeader('Accept-Encoding'), 'gzip') !== false;
            $etag = '"' . substr((string) $meta['pack_sha256'], 0, 32) . ($gzip ? '-gz' : '') . '"';
            if (self::matches((string) $request->getHeader('If-None-Match'), $etag)) {
                $this->packHeaders($etag);
                http_response_code(304);
                exit;
            }

            // 5. the bytes, read before any header is sent: an error answer must not carry the cache headers
            $blob = $repository->packBlob($regionId, $version);
            if ($blob === null) {
                Response::error('Not found', 404);
            }
            if (!$gzip) {
                $blob = gzdecode((string) $blob);
                if ($blob === false) {
                    throw new \UnexpectedValueException('the stored pack of ' . $version . ' cannot be decompressed');
                }
            }
            $this->packHeaders($etag);
            if ($gzip) {
                header('Content-Encoding: gzip');
            }
            header('Content-Length: ' . strlen((string) $blob));
            echo $blob;
            exit;
        });
    }

    /**
     * Steps 3 and 4: nothing may compress or buffer the answer, then the headers of the 200 and the 304.
     * Not Response::cacheable(): it varies on Authorization, and a new login would then throw the cached
     * pack away.
     */
    private function packHeaders(string $etag): void
    {
        ini_set('zlib.output_compression', 'Off');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        Response::corsHeaders();
        header('Content-Type: application/octet-stream');
        header('Cache-Control: private, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        header('Vary: Accept-Encoding', false);
        header('X-Content-Type-Options: nosniff');
    }

    /** Does an If-None-Match header name this validator? */
    private static function matches(string $header, string $etag): bool
    {
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate === $etag || $candidate === '*') {
                return true;
            }
        }
        return false;
    }
}
