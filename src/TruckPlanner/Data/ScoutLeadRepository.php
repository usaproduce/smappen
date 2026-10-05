<?php
declare(strict_types=1);

namespace App\TruckPlanner\Data;

use App\Core\Database;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * `tp_scout_leads`: what an owner keeps about a place that could host the truck (04_BACKEND.md 1.2, 1.4).
 *
 * One row per truck, region and place. The row is created the first time the owner touches the place
 * (a status, a note, a contact lookup, "save as spot"). It refers to the place by `place_key` and holds a
 * snapshot of its name, type and point, so the lead outlives a switch of the region's dataset.
 *
 * A read row carries:
 *
 *     id, organization_id, truck_id, region_id, place_key
 *     place_name, place_type, lat, lng      the snapshot
 *     status                                <-> lead_state
 *     notes, spot_id
 *     google_place_id                       Google's id of the place, which may be kept
 *     google                                what a contact lookup brought back, or null
 *     created_at, updated_at
 *
 * `google` is {lookup_state, name, address, phone, website, maps_uri, fetched_on, age_hours}. Its texts are
 * Google Places content: they are served for `places.contact_ttl_days` days after the lookup and no longer.
 * A row whose lookup is older reads as `google: null`, and purgeExpiredGoogle() empties its columns. The age
 * is decided in SQL with NOW(), so one clock decides it.
 *
 * Every statement carries the organization id, except the sweep over every organization that the purge
 * script asks for. No Google column is ever copied to another table.
 */
class ScoutLeadRepository
{
    /** The columns upsert() may set: row key => column. */
    public const COLUMNS = [
        'status' => 'lead_state',
        'notes' => 'notes',
        'spot_id' => 'spot_id',
        'place_name' => 'place_name',
        'place_type' => 'place_type',
        'lat' => 'lat',
        'lng' => 'lng',
    ];

    /** The Google content columns: what a lookup writes and what the purge empties. Row key => column. */
    private const GOOGLE_TEXTS = [
        'name' => 'g_name',
        'address' => 'g_address',
        'phone' => 'g_phone',
        'website' => 'g_website',
        'maps_uri' => 'g_maps_uri',
    ];

    private const READ = 'SELECT id, organization_id, truck_id, region_id, place_key, place_name, place_type, lat, lng,
                    lead_state, notes, spot_id, google_place_id,
                    g_lookup_state, g_name, g_address, g_phone, g_website, g_maps_uri,
                    DATE(g_fetched_at) AS g_fetched_on,
                    TIMESTAMPDIFF(HOUR, g_fetched_at, NOW()) AS g_age_hours,
                    (g_fetched_at IS NOT NULL AND g_fetched_at >= NOW() - INTERVAL ? DAY) AS g_fresh,
                    created_at, updated_at
               FROM tp_scout_leads';

    private ?Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db;
    }

    /**
     * Every lead of a truck in a region.
     *
     * @return array<string, array<string, mixed>> read rows keyed by place_key, in place_key order
     */
    public function forTruck(string $orgId, string $truckId, string $regionId): array
    {
        $rows = $this->db()->fetchAll(
            self::READ . '
              WHERE organization_id = ? AND truck_id = ? AND region_id = ?
              ORDER BY place_key',
            [self::ttlDays(), $orgId, $truckId, $regionId]
        );
        $out = [];
        foreach ($rows as $row) {
            $lead = self::normalise($row);
            $out[$lead['place_key']] = $lead;
        }
        return $out;
    }

    /**
     * The lead of one place, or null when the owner has not touched the place yet.
     *
     * @return array<string, mixed>|null a read row
     */
    public function find(string $orgId, string $truckId, string $regionId, string $placeKey): ?array
    {
        $row = $this->db()->fetch(
            self::READ . '
              WHERE organization_id = ? AND truck_id = ? AND region_id = ? AND place_key = ?',
            [self::ttlDays(), $orgId, $truckId, $regionId, $placeKey]
        );
        return $row === null ? null : self::normalise($row);
    }

    /**
     * Changes the given columns of the lead of a place, creating the row on first touch, and returns its
     * id. A new row gets the status `new` unless one is given.
     *
     * @param array<string, mixed> $columns any subset of the keys of COLUMNS: `status`, `notes`, `spot_id`
     *                                      and the snapshot `place_name`, `place_type`, `lat`, `lng`
     */
    public function upsert(string $orgId, string $truckId, string $regionId, string $placeKey, array $columns): string
    {
        foreach (array_keys($columns) as $key) {
            if (!is_string($key) || !isset(self::COLUMNS[$key])) {
                throw new \LogicException('tp_scout_leads: not a column that can be set: ' . $key);
            }
        }
        $id = $this->idOf($orgId, $truckId, $regionId, $placeKey);
        if ($id === null) {
            $id = Database::uuid();
            try {
                $this->addRow($id, $orgId, $truckId, $regionId, $placeKey, $columns);
                return $id;
            } catch (\PDOException $e) {
                // Two first touches at the same moment: the one-lead-per-place key let the other one in.
                // If the row is there now, this touch goes on as a change of it.
                $id = $this->idOf($orgId, $truckId, $regionId, $placeKey);
                if ($id === null) {
                    throw $e;
                }
            }
        }
        if ($columns !== []) {
            $sets = [];
            $params = [];
            foreach ($columns as $key => $value) {
                $sets[] = self::COLUMNS[$key] . ' = ?';
                $params[] = self::encode($key, $value);
            }
            $params[] = $id;
            $params[] = $orgId;
            $this->db()->query(
                'UPDATE tp_scout_leads SET ' . implode(', ', $sets) . ' WHERE id = ? AND organization_id = ?',
                $params
            );
        }
        return $id;
    }

    /**
     * Stores the outcome of a contact lookup and stamps it with the database's clock.
     *
     * @param array<string, mixed> $google `lookup_state` ("found" or "not_found") and the texts `name`,
     *        `address`, `phone`, `website`, `maps_uri` (a missing one is stored as NULL). `google_place_id`
     *        is written only when the key `place_id` is given; without it the stored id stays as it is
     */
    public function setGoogle(string $id, string $orgId, array $google): void
    {
        $state = $google['lookup_state'] ?? null;
        if ($state !== 'found' && $state !== 'not_found') {
            throw new \LogicException('tp_scout_leads: a lookup is found or not_found');
        }
        $sets = [];
        $params = [];
        if (array_key_exists('place_id', $google)) {
            $sets[] = 'google_place_id = ?';
            $params[] = $google['place_id'] === null ? null : (string) $google['place_id'];
        }
        $sets[] = 'g_lookup_state = ?';
        $params[] = $state;
        foreach (self::GOOGLE_TEXTS as $key => $column) {
            $sets[] = $column . ' = ?';
            $params[] = isset($google[$key]) ? (string) $google[$key] : null;
        }
        $params[] = $id;
        $params[] = $orgId;
        $this->db()->query(
            'UPDATE tp_scout_leads SET ' . implode(', ', $sets) . ', g_fetched_at = NOW() WHERE id = ? AND organization_id = ?',
            $params
        );
    }

    /** Links the lead to the spot that was saved from it, or takes the link away with null. */
    public function setSpot(string $id, string $orgId, ?string $spotId): void
    {
        $this->db()->query(
            'UPDATE tp_scout_leads SET spot_id = ? WHERE id = ? AND organization_id = ?',
            [$spotId, $id, $orgId]
        );
    }

    /**
     * Empties the Google content of every lead whose lookup is older than `places.contact_ttl_days` days.
     * The place id stays. Running it again finds nothing to do.
     *
     * @param string|null $orgId one organization, or null for every organization (the daily purge script)
     * @return int the number of leads that were emptied
     */
    public function purgeExpiredGoogle(?string $orgId = null): int
    {
        $where = ($orgId === null ? '' : 'organization_id = ? AND ')
            . 'g_fetched_at IS NOT NULL AND g_fetched_at < NOW() - INTERVAL ? DAY';
        $params = $orgId === null ? [self::ttlDays()] : [$orgId, self::ttlDays()];
        $empty = ['g_lookup_state = NULL'];
        foreach (self::GOOGLE_TEXTS as $column) {
            $empty[] = $column . ' = NULL';
        }
        $empty[] = 'g_fetched_at = NULL';

        $db = $this->db();
        $db->beginTransaction();
        try {
            $row = $db->fetch('SELECT COUNT(*) AS lead_count FROM tp_scout_leads WHERE ' . $where, $params);
            $count = (int) ($row['lead_count'] ?? 0);
            if ($count > 0) {
                $db->query('UPDATE tp_scout_leads SET ' . implode(', ', $empty) . ' WHERE ' . $where, $params);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        return $count;
    }

    private function idOf(string $orgId, string $truckId, string $regionId, string $placeKey): ?string
    {
        $row = $this->db()->fetch(
            'SELECT id
               FROM tp_scout_leads
              WHERE organization_id = ? AND truck_id = ? AND region_id = ? AND place_key = ?',
            [$orgId, $truckId, $regionId, $placeKey]
        );
        return $row === null ? null : (string) $row['id'];
    }

    /**
     * @param array<string, mixed> $columns keys of COLUMNS
     */
    private function addRow(string $id, string $orgId, string $truckId, string $regionId, string $placeKey, array $columns): void
    {
        $columns += ['status' => 'new'];
        $names = ['id', 'organization_id', 'truck_id', 'region_id', 'place_key'];
        $params = [$id, $orgId, $truckId, $regionId, $placeKey];
        foreach (self::COLUMNS as $key => $column) {
            $names[] = $column;
            $params[] = self::encode($key, $columns[$key] ?? null);
        }
        $this->db()->query(
            'INSERT INTO tp_scout_leads (' . implode(', ', $names) . ', created_at, updated_at)
             VALUES (' . Sql::marks(count($params)) . ', NOW(), NOW())',
            $params
        );
    }

    /** A row value as it is bound into SQL. */
    private static function encode(string $key, mixed $value): mixed
    {
        if ($key === 'status') {
            if (!is_string($value) || $value === '') {
                throw new \LogicException('tp_scout_leads: a lead has a status');
            }
            return $value;
        }
        if ($value === null) {
            return null;
        }
        if ($key === 'lat' || $key === 'lng') {
            return Sql::f((float) $value);
        }
        return (string) $value;
    }

    /**
     * @param array<string, mixed> $row a database row of READ
     * @return array<string, mixed>
     */
    private static function normalise(array $row): array
    {
        $google = null;
        if ((int) ($row['g_fresh'] ?? 0) === 1) {
            $google = ['lookup_state' => (string) $row['g_lookup_state']];
            foreach (self::GOOGLE_TEXTS as $key => $column) {
                $google[$key] = self::text($row[$column]);
            }
            $google['fetched_on'] = (string) $row['g_fetched_on'];
            $google['age_hours'] = (int) $row['g_age_hours'];
        }
        return [
            'id' => (string) $row['id'],
            'organization_id' => (string) $row['organization_id'],
            'truck_id' => (string) $row['truck_id'],
            'region_id' => (string) $row['region_id'],
            'place_key' => (string) $row['place_key'],
            'place_name' => self::text($row['place_name']),
            'place_type' => self::text($row['place_type']),
            'lat' => $row['lat'] === null ? null : (float) $row['lat'],
            'lng' => $row['lng'] === null ? null : (float) $row['lng'],
            'status' => (string) $row['lead_state'],
            'notes' => self::text($row['notes']),
            'spot_id' => self::text($row['spot_id']),
            'google_place_id' => self::text($row['google_place_id']),
            'google' => $google,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function ttlDays(): int
    {
        return max(1, (int) TpConfig::get('places.contact_ttl_days'));
    }

    private function db(): Database
    {
        return $this->db ??= Database::getInstance();
    }
}
