<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\TruckPlanner\Model\ModelError;
use App\TruckPlanner\Services\AssumptionsFactory;
use App\TruckPlanner\Services\AssumptionsService;
use App\TruckPlanner\Services\Support\Input;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * Truck Planner: the owner's overrides of seed assumptions (4.5).
 *
 * Routes (docs/truck-planner/04_BACKEND.md section 3):
 *   GET  /api/truck/assumptions  ->  show
 *   PUT  /api/truck/assumptions  ->  update
 *   POST /api/truck/assumptions/reset  ->  reset
 *
 * Every answer is `{assumptions: AssumptionsInfo}`. The seed values themselves are not sent: the browser
 * carries the same seed file, and `seeds_revision` tells it whether that is still true.
 */
class TruckAssumptionsController extends TruckBaseController
{
    /** The longest seed path is well under this. Longer text is not a path. */
    private const MAX_PATH_CHARS = 200;

    private const MAP_PATHS = ['assumptions.overrides'];

    public function show(Request $request): void
    {
        $this->run(function () use ($request): void {
            $truck = $this->truck($request);
            $info = (new AssumptionsFactory())->info($this->assumptions($truck));
            $this->ok(['assumptions' => $info], self::MAP_PATHS);
        });
    }

    /**
     * Body `{"overrides": {"<seed path>": <value> | null}}`: the changes are merged into the stored map and
     * null takes a path out. A merged map that does not validate is refused whole: the 422 names the first
     * offending path and lists every one of them in its details.
     */
    public function update(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = $this->truck($request);
            $in = new Input($this->body($request));
            $changes = $in->obj('overrides', true)?->all() ?? [];
            $max = (int) TpConfig::get('requests.max_override_entries');
            if (count($changes) > $max) {
                throw $in->error('overrides', 'must have at most ' . $max . ' entries');
            }
            try {
                $info = (new AssumptionsService())->merge($orgId, $truck, $changes);
            } catch (ModelError $e) {
                if ($e->errorCode() !== ModelError::INVALID_OVERRIDES) {
                    throw $e;
                }
                $refusal = AssumptionsService::refusal($e->details());
                Response::error($refusal['message'], 422, $refusal['details']);
                return;
            }
            $this->ok(['assumptions' => $info], self::MAP_PATHS);
        });
    }

    /** Body `{"paths": ["<seed path>", ...]}` resets those paths. `{}` or no body resets every override. */
    public function reset(Request $request): void
    {
        $this->run(function () use ($request): void {
            $orgId = $this->orgId($request);
            $truck = $this->truck($request);
            $in = new Input($this->body($request, true));
            $paths = null;
            $sent = $in->items('paths', 0, (int) TpConfig::get('requests.max_override_entries'));
            if ($sent !== null) {
                $paths = [];
                $each = $in->each('paths');
                foreach (array_keys($sent) as $i) {
                    $paths[] = (string) $each->str($i, self::MAX_PATH_CHARS, true);
                }
            }
            $info = (new AssumptionsService())->reset($orgId, $truck, $paths);
            $this->ok(['assumptions' => $info], self::MAP_PATHS);
        });
    }
}
