<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Data\TruckRepository;
use App\TruckPlanner\Services\AssumptionsFactory;
use App\TruckPlanner\Services\ProfileMapper;
use App\TruckPlanner\Services\Support\JsonSafe;
use App\TruckPlanner\Services\Support\Redactor;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpConflict;
use App\TruckPlanner\Services\Support\TpInvalid;
use App\TruckPlanner\Services\Support\TpNotFound;
use App\TruckPlanner\Services\Support\TpRateLimited;
use App\TruckPlanner\Services\Support\TpUnavailable;

/**
 * What every Truck Planner controller shares (docs/truck-planner/04_BACKEND.md 2.5 and section 6).
 *
 * An action is `public function name(Request $request): void` and consists of run() around: the guards
 * (orgId, truck), Input validation of body() or the query, one service call, ok().
 *
 *     public function show(Request $request): void
 *     {
 *         $this->run(function () use ($request): void {
 *             $orgId = $this->orgId($request);
 *             $truck = $this->truck($request);
 *             $this->ok(['spot' => $this->spots->get($orgId, $truck, (string) $request->getParam('id'))]);
 *         });
 *     }
 *
 * Rules the helpers keep: the tenant is the caller's organization and nothing from the request; an id of
 * another organization answers like a missing one (404); nothing here answers 401, which belongs to the
 * auth middleware alone and logs the browser out; no upstream text or exception text reaches the client.
 *
 * Response::* ends the process, so nothing runs after ok() or after a guard that answered: do side effects
 * (cache writes, ledger rows) first, and never answer inside an open transaction.
 *
 * Constructors of subclasses take no required argument: the router builds controllers with `new $class()`.
 */
abstract class TruckBaseController
{
    private ?TruckRepository $truckRepository = null;
    private ?RegionRepository $regionRepository = null;

    /**
     * Runs an action and maps what it throws:
     * TpInvalid 422 (with its details), TpNotFound 404, TpConflict 409, TpRateLimited 429, TpUnavailable 503.
     * Anything else is a server defect: it is logged without secrets and answered with one fixed sentence.
     */
    protected function run(callable $fn): void
    {
        try {
            $fn();
        } catch (TpInvalid $e) {
            Response::error($e->getMessage(), 422, $e->details());
        } catch (TpNotFound $e) {
            Response::error($e->getMessage(), 404);
        } catch (TpConflict $e) {
            Response::error($e->getMessage(), 409);
        } catch (TpRateLimited $e) {
            Response::error($e->getMessage(), 429);
        } catch (TpUnavailable $e) {
            Response::error($e->getMessage(), 503);
        } catch (\Throwable $e) {
            error_log('[tp] ' . get_class($e) . ': ' . Redactor::text($e->getMessage()));
            Response::error('Truck Planner could not complete this request', 500);
        }
    }

    /** The caller's organization. An account without one is answered 403. */
    protected function orgId(Request $r): string
    {
        $org = $r->user['organization_id'] ?? null;
        if (!is_string($org) || $org === '') {
            Response::error('This account has no workspace', 403);
        }
        return (string) $org;
    }

    /** The caller's user id, for `created_by` columns. Null when the request carries none. */
    protected function userId(Request $r): ?string
    {
        $id = $r->user['id'] ?? null;
        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The organization's truck: the truck value every service takes as `$truck`.
     *
     * It is the normalised row of TruckRepository::findByOrg() with one key added, `profile`, the
     * TruckProfile the model takes. So it reads as the API's TruckRecord (`id`, `timezone`, `base_state`,
     * `base_county_fips`, `profile`, `created_at`, `updated_at`) and also carries `organization_id`,
     * `overrides` and `overrides_seeds_rev`. Send it to a client through ProfileMapper::toRecord() only.
     *
     * Without a truck: 409 "Set up your truck first" when required, else null.
     *
     * @return array<string, mixed>|null
     */
    protected function truck(Request $r, bool $required = true): ?array
    {
        $row = $this->trucks()->findByOrg($this->orgId($r));
        if ($row === null) {
            if ($required) {
                Response::error('Set up your truck first', 409);
            }
            return null;
        }
        $row['profile'] = (new ProfileMapper())->toRecord($row)['profile'];
        return $row;
    }

    /**
     * `A`, the truck's Assumptions (seed file, the owner's overrides, the region block). Region `none`
     * and no overrides without a truck.
     *
     * @param array<string, mixed>|null $truck the value of truck()
     * @return array<string, mixed>
     */
    protected function assumptions(?array $truck): array
    {
        $regionRow = null;
        $regionId = $truck['profile']['region_id'] ?? null;
        if (is_string($regionId) && $regionId !== '' && $regionId !== 'none') {
            $regionRow = $this->regions()->find($regionId);
        }
        return (new AssumptionsFactory())->forTruck($truck, $regionRow);
    }

    /**
     * The JSON object of the request body.
     *
     * 413 above 262,144 bytes. 422 "Request body must be a JSON object" when the body is missing, is not
     * JSON or is not an object. With `$optional` an empty body is [] (routes that work without one).
     *
     * @return array<int|string, mixed>
     */
    protected function body(Request $r, bool $optional = false): array
    {
        $raw = $r->getRawBody();
        if (strlen($raw) > (int) TpConfig::get('limits.max_body_bytes')) {
            Response::error('Request body is too large', 413);
        }
        $text = ltrim($raw);
        if ($text === '' && $optional) {
            return [];
        }
        $decoded = ($text !== '' && $text[0] === '{') ? json_decode($text, true, 64) : null;
        if (!is_array($decoded)) {
            Response::error('Request body must be a JSON object', 422);
        }
        return (array) $decoded;
    }

    /**
     * Sends `$data` in the house envelope and ends the request.
     *
     * A number that is not finite and a text that is not valid UTF-8 are sent as null (and logged); an
     * empty array at one of `$mapPaths` is sent as {} instead of []. The payload is encoded once on trial,
     * so a response is never an empty 200.
     *
     * @param array<int|string, mixed> $data
     * @param list<string> $mapPaths dotted paths of the payload's maps; `*` matches the items of a list
     */
    protected function ok(array $data, array $mapPaths = [], ?string $message = null, int $status = 200): void
    {
        ini_set('serialize_precision', '-1');
        $clean = JsonSafe::clean($data, $mapPaths);
        if (json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) === false) {
            error_log('[tp] response could not be encoded: ' . Redactor::text(json_last_error_msg()));
            Response::error('Truck Planner could not complete this request', 500);
        }
        Response::success($clean, $message, $status);
    }

    /**
     * An action that is registered but not built yet: the guards run as they will later (403 without a
     * workspace, 409 without a truck where the route needs one), then the answer is 501.
     */
    protected function stub(Request $r, bool $needsTruck = true): void
    {
        $this->run(function () use ($r, $needsTruck): void {
            $this->orgId($r);
            $this->truck($r, $needsTruck);
            Response::error('Not implemented yet', 501);
        });
    }

    private function trucks(): TruckRepository
    {
        return $this->truckRepository ??= new TruckRepository();
    }

    private function regions(): RegionRepository
    {
        return $this->regionRepository ??= new RegionRepository();
    }
}
