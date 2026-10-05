<?php
declare(strict_types=1);

namespace App\TruckPlanner\Data;

use App\Core\Database;
use App\TruckPlanner\Services\Support\Money;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * `tp_drive_legs`: the shared cache of Google drive legs (04_BACKEND.md 1.2, 1.4, 5.3).
 *
 * A row is one directed leg between two rounded points for one set of routing options (`route_key`), as
 * Google answered it. There is no organization column: the table holds nothing of the owner's, and it is
 * read only through keys the caller supplies.
 *
 * Google content is kept for 30 days and not a day longer. findFresh() never returns an older row, and
 * purgeExpired() deletes them. The database server's clock decides age (`NOW()` in every statement).
 * A straight-line estimate is never written here: the callers hand over Google answers only.
 *
 * A pair key is the list [o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4] (two point keys of LegKey::of()). Rows
 * are returned keyed by pairId() of that list.
 *
 *     row = { pair: [int x4], src: "google_routes"|"google_distance_matrix", route_found: bool,
 *             duration_s: int, distance_m: int, toll_state: 0..3, toll: float? (dollars),
 *             fetched_on: "YYYY-MM-DD", age_days: int }
 *
 * `toll_state`: 0 not asked, 1 none (asked, no toll on the route), 2 estimate (`toll` set), 3 unknown
 * (tolls on the route, no US dollar price).
 */
class DriveLegRepository
{
    public const SRC_ROUTES = 'google_routes';
    public const SRC_LEGACY = 'google_distance_matrix';

    public const TOLL_NOT_ASKED = 0;
    public const TOLL_NONE = 1;
    public const TOLL_ESTIMATE = 2;
    public const TOLL_UNKNOWN = 3;

    /** `toll_state` as a number => DriveLeg.toll_state */
    public const TOLL_STATES = ['not_asked', 'none', 'estimate', 'unknown'];

    private const ROWS_PER_INSERT = 100;

    private ?Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db;
    }

    /**
     * The text form of a pair key: "390030,-774050,389600,-773600".
     *
     * @param array<int, int> $pairKey [o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4]
     */
    public static function pairId(array $pairKey): string
    {
        $pairKey = array_values($pairKey);
        if (count($pairKey) !== 4) {
            throw new \LogicException('a pair key is four whole numbers');
        }
        return (int) $pairKey[0] . ',' . (int) $pairKey[1] . ',' . (int) $pairKey[2] . ',' . (int) $pairKey[3];
    }

    /**
     * The cached legs of these pairs that are at most 30 days old. A pair without such a row is absent.
     *
     * @param list<array<int, int>> $pairKeys
     * @return array<string, array<string, mixed>> rows keyed by pairId()
     */
    public function findFresh(string $routeKey, array $pairKeys): array
    {
        $unique = [];
        foreach ($pairKeys as $pairKey) {
            $unique[self::pairId($pairKey)] = array_map('intval', array_values($pairKey));
        }
        $out = [];
        $perStatement = max(1, (int) TpConfig::get('routing.cache_pairs_per_statement'));
        foreach (array_chunk(array_values($unique), $perStatement) as $chunk) {
            $params = [$routeKey];
            foreach ($chunk as $pairKey) {
                array_push($params, ...$pairKey);
            }
            $rows = $this->db()->fetchAll(
                'SELECT o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4, src, route_found, duration_s, distance_m,
                        toll_state, toll_cents, DATE(fetched_at) AS fetched_on,
                        TIMESTAMPDIFF(DAY, fetched_at, NOW()) AS age_days
                   FROM tp_drive_legs
                  WHERE route_key = ?
                    AND (o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4) IN (' . self::tuples(count($chunk)) . ')
                    AND fetched_at >= NOW() - INTERVAL ' . self::ttlDays() . ' DAY',
                $params
            );
            foreach ($rows as $row) {
                $leg = self::normalise($row);
                $out[self::pairId($leg['pair'])] = $leg;
            }
        }
        return $out;
    }

    /**
     * Stores Google answers, replacing what the cache held for the same pair and route key, and stamps
     * them with the database's present time. Rows are written in key order, so two requests that store
     * overlapping legs take their row locks in the same order.
     *
     * @param list<array<string, mixed>> $rows each { pair: [int x4], route_key, src, route_found: bool,
     *                                         duration_s: int, distance_m: int, toll_state: 0..3,
     *                                         toll: float? (dollars) }
     */
    public function upsertMany(array $rows): void
    {
        $byKey = [];
        foreach ($rows as $row) {
            $pair = array_map('intval', array_values((array) $row['pair']));
            $src = (string) $row['src'];
            if ($src !== self::SRC_ROUTES && $src !== self::SRC_LEGACY) {
                throw new \LogicException('tp_drive_legs holds Google answers only');
            }
            $tollState = (int) $row['toll_state'];
            if (!isset(self::TOLL_STATES[$tollState])) {
                throw new \LogicException('tp_drive_legs: not a toll state: ' . $tollState);
            }
            $toll = $row['toll'] ?? null;
            $routeKey = (string) $row['route_key'];
            $byKey[self::pairId($pair) . ',' . $routeKey] = [
                $pair[0],
                $pair[1],
                $pair[2],
                $pair[3],
                $routeKey,
                $src,
                Sql::b((bool) $row['route_found']),
                max(0, (int) $row['duration_s']),
                max(0, (int) $row['distance_m']),
                $tollState,
                $toll === null ? null : Money::toCents((float) $toll),
            ];
        }
        if ($byKey === []) {
            return;
        }
        uasort($byKey, static function (array $a, array $b): int {
            return [$a[0], $a[1], $a[2], $a[3], $a[4]] <=> [$b[0], $b[1], $b[2], $b[3], $b[4]];
        });
        foreach (array_chunk(array_values($byKey), self::ROWS_PER_INSERT) as $chunk) {
            $params = [];
            foreach ($chunk as $values) {
                array_push($params, ...$values);
            }
            $this->db()->query(
                'INSERT INTO tp_drive_legs
                        (o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4, route_key, src, route_found, duration_s,
                         distance_m, toll_state, toll_cents, fetched_at)
                 VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')) . '
                 ON DUPLICATE KEY UPDATE src = VALUES(src), route_found = VALUES(route_found),
                        duration_s = VALUES(duration_s), distance_m = VALUES(distance_m),
                        toll_state = VALUES(toll_state), toll_cents = VALUES(toll_cents), fetched_at = NOW()',
                $params
            );
        }
    }

    /**
     * Deletes legs whose 30 days have ended, at most `$limit` of them (0 = all), and answers how many.
     * The count is read in the same transaction as the delete; when nothing has expired nothing is
     * written.
     */
    public function purgeExpired(int $limit = 0): int
    {
        $limit = max(0, $limit);
        $expired = 'fetched_at < NOW() - INTERVAL ' . self::ttlDays() . ' DAY';
        $db = $this->db();
        $db->beginTransaction();
        try {
            $row = $db->fetch('SELECT COUNT(*) AS expired_legs FROM tp_drive_legs WHERE ' . $expired);
            $count = (int) ($row['expired_legs'] ?? 0);
            $deleted = $limit > 0 ? min($count, $limit) : $count;
            if ($deleted > 0) {
                $db->query('DELETE FROM tp_drive_legs WHERE ' . $expired . ($limit > 0 ? ' LIMIT ' . $limit : ''));
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        return $deleted;
    }

    /** The lifetime of a Google leg in whole days (30), as a number that is safe inside a statement. */
    private static function ttlDays(): int
    {
        return max(1, (int) TpConfig::get('routing.leg_ttl_days'));
    }

    /** "(?, ?, ?, ?), (?, ?, ?, ?)" for `$n` pair keys. */
    private static function tuples(int $n): string
    {
        return implode(', ', array_fill(0, $n, '(?, ?, ?, ?)'));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalise(array $row): array
    {
        return [
            'pair' => [(int) $row['o_lat_e4'], (int) $row['o_lng_e4'], (int) $row['d_lat_e4'], (int) $row['d_lng_e4']],
            'src' => (string) $row['src'],
            'route_found' => (int) $row['route_found'] === 1,
            'duration_s' => (int) $row['duration_s'],
            'distance_m' => (int) $row['distance_m'],
            'toll_state' => (int) $row['toll_state'],
            'toll' => $row['toll_cents'] === null ? null : Money::fromCents((int) $row['toll_cents']),
            'fetched_on' => (string) $row['fetched_on'],
            'age_days' => (int) $row['age_days'],
        ];
    }

    private function db(): Database
    {
        return $this->db ??= Database::getInstance();
    }
}
