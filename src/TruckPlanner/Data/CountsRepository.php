<?php
declare(strict_types=1);

namespace App\TruckPlanner\Data;

use App\Core\Database;

/**
 * Row counts on the owner tables, for the bootstrap answer and the spot list (04_BACKEND.md 2.2).
 *
 * It lives in the foundation package so that the first screens need no repository of a later package.
 * Every statement carries the organization id.
 */
class CountsRepository
{
    private ?Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db;
    }

    /**
     * @return array{spots: int, plans: int, services: int, leads: int} spots that are not archived, and
     *         every plan, service log and scout lead of the organization
     */
    public function forOrg(string $orgId): array
    {
        $row = $this->db()->fetch(
            'SELECT (SELECT COUNT(*) FROM tp_spots WHERE organization_id = ? AND archived_at IS NULL) AS spots,
                    (SELECT COUNT(*) FROM tp_plans WHERE organization_id = ?) AS plans,
                    (SELECT COUNT(*) FROM tp_service_logs WHERE organization_id = ?) AS services,
                    (SELECT COUNT(*) FROM tp_scout_leads WHERE organization_id = ?) AS leads',
            [$orgId, $orgId, $orgId, $orgId]
        );
        return [
            'spots' => (int) ($row['spots'] ?? 0),
            'plans' => (int) ($row['plans'] ?? 0),
            'services' => (int) ($row['services'] ?? 0),
            'leads' => (int) ($row['leads'] ?? 0),
        ];
    }

    /**
     * The number of logged services per spot and the date of the newest one.
     *
     * @return array<string, array{count: int, last_date: ?string}> keyed by spot id; a spot without logs
     *         is absent
     */
    public function logsBySpot(string $orgId, string $truckId): array
    {
        $rows = $this->db()->fetchAll(
            'SELECT spot_id, COUNT(*) AS log_count, MAX(service_date) AS last_date
               FROM tp_service_logs
              WHERE organization_id = ? AND truck_id = ? AND spot_id IS NOT NULL
              GROUP BY spot_id',
            [$orgId, $truckId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['spot_id']] = [
                'count' => (int) $row['log_count'],
                'last_date' => $row['last_date'] === null ? null : (string) $row['last_date'],
            ];
        }
        return $out;
    }

    private function db(): Database
    {
        return $this->db ??= Database::getInstance();
    }
}
