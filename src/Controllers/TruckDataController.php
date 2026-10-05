<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\TruckPlanner\Services\DataPurgeService;
use App\TruckPlanner\Services\ExportService;
use App\TruckPlanner\Services\SourcesService;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * Truck Planner: export, deletion and sources (4.16).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   GET  /api/truck/export  ->  export
 *   POST /api/truck/data/delete  ->  destroy
 *   GET  /api/truck/sources  ->  sources
 *
 * The export is the one JSON answer of Truck Planner that is not the house envelope: a bare document,
 * written to the client as it is read and kept nowhere on the server. Deletion removes the truck data of
 * the caller's organization and nothing else; the route table lets only the account owner or an admin
 * reach it, and it wants the phrase typed out.
 */
class TruckDataController extends TruckBaseController
{
    /**
     * The owner's data as a download. 409 without a truck.
     *
     * Until the first byte is written an error is answered like any other. After that the document is
     * always finished as valid JSON, and says `"incomplete": true` when a read failed on the way.
     */
    public function export(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $this->truck($request);
            @set_time_limit((int) TpConfig::get('requests.long_request_seconds'));
            (new ExportService())->stream($orgId, static function (string $filename): void {
                // Nothing may hold the document back or compress it on the way: it is sent as it is read.
                ini_set('zlib.output_compression', 'Off');
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                Response::corsHeaders();
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Access-Control-Expose-Headers: Content-Disposition');
                header('Cache-Control: no-store');
            });
            exit;
        });
    }

    /**
     * Deletes the organization's truck data. The body must be {"confirm": "delete my truck data"}:
     * anything else is refused and nothing is deleted. Without a truck there is nothing to delete, and
     * the answer says so with zeros.
     */
    public function destroy(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $deleted = (new DataPurgeService())->deleteConfirmed($orgId, $this->body($request));
            $this->ok(['deleted' => $deleted], [], 'Truck data deleted');
        });
    }

    /** Where the numbers come from: versions, the region's dataset, the fuel price and the source lines. */
    public function sources(Request $request): void
    {
        $this->run(function () use ($request): void {
            $this->orgId($request);
            $truck = $this->truck($request, false);
            $this->ok((new SourcesService())->build($truck));
        });
    }
}
