<?php
declare(strict_types=1);

namespace App\TruckPlanner\Data;

use App\Core\Database;
use App\TruckPlanner\Services\Support\Money;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * `tp_drive_overrides`: the owner's corrections of a drive (04_BACKEND.md 1.2, 1.4, 4.10).
 *
 * A correction says what one directed leg takes this owner (`minutes`) and what toll it costs (`toll`).
 * It is the owner's own data: it is kept until the owner deletes it and it never expires, unlike the
 * Google leg it sits on top of. It is keyed like a cached leg, by the two rounded points (LegKey::of()),
 * and there is at most one per truck and directed pair.
 *
 *     row = { id, organization_id, truck_id, from_key: [lat_e4, lng_e4], to_key: [lat_e4, lng_e4],
 *             minutes: int?, toll: float? (dollars), note: string, created_at, updated_at }
 *
 * Every statement carries the organization id. A pair key is [o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4];
 * findForPairs() answers keyed by DriveLegRepository::pairId() of it.
 */
class DriveOverrideRepository
{
    private const COLUMNS = 'id, organization_id, truck_id, o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4,
                    override_minutes, toll_cents, note, created_at, updated_at';

    /** SQLSTATE of a broken unique key: two saves of one new correction met. */
    private const DUPLICATE_KEY = '23000';

    private ?Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db;
    }

    /**
     * Every correction of a truck, in id order: the order the export lists them in too.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTruck(string $orgId, string $truckId): array
    {
        $rows = $this->db()->fetchAll(
            'SELECT ' . self::COLUMNS . '
               FROM tp_drive_overrides
              WHERE organization_id = ? AND truck_id = ?
              ORDER BY id',
            [$orgId, $truckId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalise($row);
        }
        return $out;
    }

    /**
     * One correction of the organization, or null. Another organization's id is not found.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $orgId): ?array
    {
        $row = $this->db()->fetch(
            'SELECT ' . self::COLUMNS . ' FROM tp_drive_overrides WHERE id = ? AND organization_id = ?',
            [$id, $orgId]
        );
        return $row === null ? null : self::normalise($row);
    }

    /**
     * The corrections a truck has for these directed pairs.
     *
     * @param list<array<int, int>> $pairKeys
     * @return array<string, array<string, mixed>> rows keyed by DriveLegRepository::pairId()
     */
    public function findForPairs(string $orgId, string $truckId, array $pairKeys): array
    {
        $unique = [];
        foreach ($pairKeys as $pairKey) {
            $unique[DriveLegRepository::pairId($pairKey)] = array_map('intval', array_values($pairKey));
        }
        $out = [];
        $perStatement = max(1, (int) TpConfig::get('routing.cache_pairs_per_statement'));
        foreach (array_chunk(array_values($unique), $perStatement) as $chunk) {
            $params = [$orgId, $truckId];
            foreach ($chunk as $pairKey) {
                array_push($params, ...$pairKey);
            }
            $rows = $this->db()->fetchAll(
                'SELECT ' . self::COLUMNS . '
                   FROM tp_drive_overrides
                  WHERE organization_id = ? AND truck_id = ?
                    AND (o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4) IN ('
                        . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?)')) . ')',
                $params
            );
            foreach ($rows as $row) {
                $override = self::normalise($row);
                $out[DriveLegRepository::pairId(array_merge($override['from_key'], $override['to_key']))] = $override;
            }
        }
        return $out;
    }

    /**
     * Saves the correction of one directed pair and returns its id: the row of that pair is changed when
     * there is one, else a new one is written. Both values are stored as given (null clears one).
     *
     * @param array<int, int> $fromKey [lat_e4, lng_e4]
     * @param array<int, int> $toKey [lat_e4, lng_e4]
     */
    public function upsert(
        string $orgId,
        string $truckId,
        array $fromKey,
        array $toKey,
        ?int $minutes,
        ?int $tollCents,
        string $note
    ): string {
        $pair = self::pair($fromKey, $toKey);
        $existing = $this->idOfPair($orgId, $truckId, $pair);
        if ($existing !== null) {
            $this->write($existing, $orgId, $minutes, $tollCents, $note);
            return $existing;
        }
        $id = Database::uuid();
        try {
            $this->db()->query(
                'INSERT INTO tp_drive_overrides
                        (id, organization_id, truck_id, o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4,
                         override_minutes, toll_cents, note, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$id, $orgId, $truckId, $pair[0], $pair[1], $pair[2], $pair[3], $minutes, $tollCents, $note]
            );
        } catch (\PDOException $e) {
            // Two first saves of the same pair arrived together: the later one changes the row that is there.
            $winner = (string) $e->getCode() === self::DUPLICATE_KEY ? $this->idOfPair($orgId, $truckId, $pair) : null;
            if ($winner === null) {
                throw $e;
            }
            $this->write($winner, $orgId, $minutes, $tollCents, $note);
            return $winner;
        }
        return $id;
    }

    /** Removes one correction of the organization. An id that is not there changes nothing. */
    public function delete(string $id, string $orgId): void
    {
        $this->db()->query('DELETE FROM tp_drive_overrides WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    }

    /**
     * A point moved a short way: the truck's corrections that start or end at the old key are re-keyed
     * to the new one, in one transaction. A correction stays where it is when the new pair has a
     * correction already, or when the move would make its two ends the same place. Nothing is deleted.
     *
     * @param array<int, int> $oldKey [lat_e4, lng_e4]
     * @param array<int, int> $newKey [lat_e4, lng_e4]
     */
    public function movePoint(string $orgId, string $truckId, array $oldKey, array $newKey): void
    {
        $old = [(int) array_values($oldKey)[0], (int) array_values($oldKey)[1]];
        $new = [(int) array_values($newKey)[0], (int) array_values($newKey)[1]];
        if ($old === $new) {
            return;
        }
        $db = $this->db();
        $db->beginTransaction();
        try {
            $rows = $db->fetchAll(
                'SELECT id, o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4
                   FROM tp_drive_overrides
                  WHERE organization_id = ? AND truck_id = ?
                    AND ((o_lat_e4 = ? AND o_lng_e4 = ?) OR (d_lat_e4 = ? AND d_lng_e4 = ?)
                      OR (o_lat_e4 = ? AND o_lng_e4 = ?) OR (d_lat_e4 = ? AND d_lng_e4 = ?))
                  ORDER BY id',
                [$orgId, $truckId, $old[0], $old[1], $old[0], $old[1], $new[0], $new[1], $new[0], $new[1]]
            );
            $taken = [];
            foreach ($rows as $row) {
                $taken[self::pairText($row)] = true;
            }
            foreach ($rows as $row) {
                $from = [(int) $row['o_lat_e4'], (int) $row['o_lng_e4']];
                $to = [(int) $row['d_lat_e4'], (int) $row['d_lng_e4']];
                if ($from !== $old && $to !== $old) {
                    continue;
                }
                $movedFrom = $from === $old ? $new : $from;
                $movedTo = $to === $old ? $new : $to;
                $target = DriveLegRepository::pairId(array_merge($movedFrom, $movedTo));
                if ($movedFrom === $movedTo || isset($taken[$target])) {
                    continue;
                }
                $db->query(
                    'UPDATE tp_drive_overrides
                        SET o_lat_e4 = ?, o_lng_e4 = ?, d_lat_e4 = ?, d_lng_e4 = ?
                      WHERE id = ? AND organization_id = ?',
                    [$movedFrom[0], $movedFrom[1], $movedTo[0], $movedTo[1], (string) $row['id'], $orgId]
                );
                unset($taken[self::pairText($row)]);
                $taken[$target] = true;
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /**
     * @param array<int, int> $pair
     */
    private function idOfPair(string $orgId, string $truckId, array $pair): ?string
    {
        $row = $this->db()->fetch(
            'SELECT id
               FROM tp_drive_overrides
              WHERE organization_id = ? AND truck_id = ?
                AND o_lat_e4 = ? AND o_lng_e4 = ? AND d_lat_e4 = ? AND d_lng_e4 = ?',
            [$orgId, $truckId, $pair[0], $pair[1], $pair[2], $pair[3]]
        );
        return $row === null ? null : (string) $row['id'];
    }

    private function write(string $id, string $orgId, ?int $minutes, ?int $tollCents, string $note): void
    {
        $this->db()->query(
            'UPDATE tp_drive_overrides
                SET override_minutes = ?, toll_cents = ?, note = ?
              WHERE id = ? AND organization_id = ?',
            [$minutes, $tollCents, $note, $id, $orgId]
        );
    }

    /**
     * @param array<int, int> $fromKey
     * @param array<int, int> $toKey
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private static function pair(array $fromKey, array $toKey): array
    {
        $from = array_values($fromKey);
        $to = array_values($toKey);
        if (count($from) !== 2 || count($to) !== 2) {
            throw new \LogicException('a point key is [lat_e4, lng_e4]');
        }
        return [(int) $from[0], (int) $from[1], (int) $to[0], (int) $to[1]];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function pairText(array $row): string
    {
        return DriveLegRepository::pairId([
            (int) $row['o_lat_e4'], (int) $row['o_lng_e4'], (int) $row['d_lat_e4'], (int) $row['d_lng_e4'],
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalise(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'organization_id' => (string) $row['organization_id'],
            'truck_id' => (string) $row['truck_id'],
            'from_key' => [(int) $row['o_lat_e4'], (int) $row['o_lng_e4']],
            'to_key' => [(int) $row['d_lat_e4'], (int) $row['d_lng_e4']],
            'minutes' => $row['override_minutes'] === null ? null : (int) $row['override_minutes'],
            'toll' => $row['toll_cents'] === null ? null : Money::fromCents((int) $row['toll_cents']),
            'note' => (string) $row['note'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private function db(): Database
    {
        return $this->db ??= Database::getInstance();
    }
}
