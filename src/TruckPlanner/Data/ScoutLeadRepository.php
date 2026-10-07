<?php
declare(strict_types=1);

namespace App\TruckPlanner\Data;

use App\Core\Database;

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
 *     place_name, place_type, lat, lng      the snapshot (OpenStreetMap values)
 *     status                                <-> lead_state
 *     notes, spot_id
 *     google_place_id                       Google's id of the place, or null
 *     lookup_state                          "found", "not_found", or null before any lookup
 *     matched_at                            when Google was asked for the place by name (g_fetched_at)
 *     created_at, updated_at
 *
 * Of a contact lookup the table keeps three things and nothing else: Google's id of the place, whether
 * the search by name found the place, and when. Google's terms let a place id be kept; the name, address,
 * phone, website and Maps link of an answer are Google Places content, and there is no column, no method
 * and no argument here that could take them (setMatch() has a place id for its only value).
 *
 * Every statement carries the organization id.
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

    /** What Google's id of a place looks like. Anything else is not stored as one. */
    public const PLACE_ID_FORM = '/^[A-Za-z0-9_\-]{1,255}$/D';

    private const READ = 'SELECT id, organization_id, truck_id, region_id, place_key, place_name, place_type, lat, lng,
                    lead_state, notes, spot_id, google_place_id, g_lookup_state, g_fetched_at,
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
            [$orgId, $truckId, $regionId]
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
            [$orgId, $truckId, $regionId, $placeKey]
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
     * Records how a search for the place by name ended, stamped with the database's clock: Google's id of
     * the place when it was found, and no id when it was not. This is everything a lookup leaves behind.
     *
     * The three columns are always written together, so `google_place_id` is the id of the last search:
     * a search that finds nothing takes an earlier id away.
     *
     * @param string|null $placeId Google's id of the matched place; null when the place was found without
     *                             a usable id, and always null when it was not found
     */
    public function setMatch(string $id, string $orgId, bool $found, ?string $placeId): void
    {
        if ($placeId !== null && (!$found || preg_match(self::PLACE_ID_FORM, $placeId) !== 1)) {
            throw new \LogicException('tp_scout_leads: only the id of a place that was found is kept');
        }
        $this->db()->query(
            'UPDATE tp_scout_leads SET google_place_id = ?, g_lookup_state = ?, g_fetched_at = NOW()
              WHERE id = ? AND organization_id = ?',
            [$placeId, $found ? 'found' : 'not_found', $id, $orgId]
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
            'lookup_state' => self::text($row['g_lookup_state']),
            'matched_at' => self::text($row['g_fetched_at']),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function db(): Database
    {
        return $this->db ??= Database::getInstance();
    }
}
