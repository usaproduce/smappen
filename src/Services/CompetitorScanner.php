<?php
namespace App\Services;

use App\Core\Database;

/**
 * One run = one scan. Pulls current Places search results for each
 * configured place_type, compares to the monitor's tracked_places, emits
 * alerts for new/gone/moved/rating-changed.
 *
 * Search center: monitor's area centroid if area_id is set, otherwise the
 * project's center. Radius: 5km default — could be made configurable later.
 *
 * MOVE detection: > 150m centroid shift since last scan.
 * RATING change: > 0.3 stars OR > 25% review count change.
 */
class CompetitorScanner
{
    private const DEFAULT_RADIUS_M = 5000;
    private const MOVE_THRESHOLD_M = 150;
    private const RATING_DELTA = 0.3;
    private const REVIEW_PCT_DELTA = 0.25;

    public function scan(array $monitor): array
    {
        $db = Database::getInstance();
        $scanId = Database::uuid();
        $db->query(
            'INSERT INTO competitor_scans (id, monitor_id, started_at)
             VALUES (?, ?, NOW())',
            [$scanId, $monitor['id']]
        );

        [$lat, $lng, $radius] = $this->resolveCenter($monitor);

        $places = $this->fetchPlaces($lat, $lng, $radius, $monitor);
        $existing = $db->fetchAll(
            'SELECT * FROM tracked_places WHERE monitor_id = ?',
            [$monitor['id']]
        );
        $existingByPlace = [];
        foreach ($existing as $e) $existingByPlace[$e['place_id']] = $e;

        $now = date('Y-m-d H:i:s');
        $newCount = 0;
        $movedCount = 0;
        $ratingChangeCount = 0;
        $goneCount = 0;
        $seenIds = [];
        $alerts = [];

        foreach ($places as $p) {
            $pid = $p['id'] ?? null;
            if (!$pid) continue;
            $seenIds[$pid] = true;
            $name = $p['displayName']['text'] ?? ($p['displayName'] ?? null);
            $loc = $p['location'] ?? null;
            $pLat = $loc['latitude'] ?? null;
            $pLng = $loc['longitude'] ?? null;
            $rating = $p['rating'] ?? null;
            $reviews = $p['userRatingCount'] ?? null;
            $types = $p['types'] ?? [];

            if (!isset($existingByPlace[$pid])) {
                $tpId = Database::uuid();
                $db->query(
                    'INSERT INTO tracked_places
                       (id, monitor_id, place_id, name, lat, lng, location, rating,
                        user_ratings_total, types, last_seen_scan_id, first_seen_at, last_seen_at, is_gone)
                     VALUES (?, ?, ?, ?, ?, ?, ST_GeomFromText(?, 4326), ?, ?, ?, ?, ?, ?, 0)',
                    [
                        $tpId, $monitor['id'], $pid, $name, $pLat, $pLng,
                        $pLat !== null && $pLng !== null ? "POINT({$pLng} {$pLat})" : 'POINT(0 0)',
                        $rating, $reviews,
                        json_encode($types),
                        $scanId, $now, $now,
                    ]
                );
                $newCount++;
                $alerts[] = [
                    'type' => 'new',
                    'severity' => 'info',
                    'place_id' => $pid,
                    'title' => 'New competitor: ' . ($name ?? $pid),
                    'detail' => ['lat' => $pLat, 'lng' => $pLng, 'rating' => $rating],
                ];
            } else {
                $prev = $existingByPlace[$pid];
                $changed = false;
                $detail = [];

                // Move detection (use Haversine in PHP — quick, no SQL round-trip)
                if ($pLat !== null && $pLng !== null && $prev['lat'] !== null && $prev['lng'] !== null) {
                    $moveM = self::haversineMeters((float)$prev['lat'], (float)$prev['lng'], (float)$pLat, (float)$pLng);
                    if ($moveM >= self::MOVE_THRESHOLD_M) {
                        $movedCount++;
                        $detail['moved_m'] = (int)$moveM;
                        $alerts[] = [
                            'type' => 'moved',
                            'severity' => 'warn',
                            'place_id' => $pid,
                            'title' => ($name ?? $pid) . ' moved (' . (int)$moveM . ' m)',
                            'detail' => ['from' => [$prev['lat'], $prev['lng']], 'to' => [$pLat, $pLng]],
                        ];
                        $changed = true;
                    }
                }
                // Rating shift
                if ($rating !== null && $prev['rating'] !== null) {
                    $delta = (float)$rating - (float)$prev['rating'];
                    if (abs($delta) >= self::RATING_DELTA) {
                        $ratingChangeCount++;
                        $alerts[] = [
                            'type' => $delta > 0 ? 'rating_jump' : 'rating_drop',
                            'severity' => $delta > 0 ? 'info' : 'high',
                            'place_id' => $pid,
                            'title' => ($name ?? $pid) . ' rating ' . ($delta > 0 ? '+' : '') . round($delta, 1),
                            'detail' => ['from' => (float)$prev['rating'], 'to' => (float)$rating],
                        ];
                        $changed = true;
                    }
                }
                $db->query(
                    'UPDATE tracked_places
                     SET name = ?, lat = ?, lng = ?,
                         location = ST_GeomFromText(?, 4326),
                         rating = ?, user_ratings_total = ?, types = ?,
                         last_seen_scan_id = ?, last_seen_at = ?, is_gone = 0
                     WHERE id = ?',
                    [
                        $name, $pLat, $pLng,
                        $pLat !== null && $pLng !== null ? "POINT({$pLng} {$pLat})" : 'POINT(0 0)',
                        $rating, $reviews, json_encode($types),
                        $scanId, $now, $prev['id'],
                    ]
                );
            }
        }

        // Gone detection — anything in existing that was not seen in this scan
        foreach ($existing as $e) {
            if (isset($seenIds[$e['place_id']])) continue;
            if ((int)$e['is_gone'] === 1) continue;
            $db->query(
                'UPDATE tracked_places SET is_gone = 1 WHERE id = ?',
                [$e['id']]
            );
            $goneCount++;
            $alerts[] = [
                'type' => 'gone',
                'severity' => 'warn',
                'place_id' => $e['place_id'],
                'title' => 'Competitor gone: ' . ($e['name'] ?? $e['place_id']),
                'detail' => null,
            ];
        }

        // Persist alerts + project-level notifications
        foreach ($alerts as $a) {
            $aid = Database::uuid();
            $db->query(
                'INSERT INTO competitor_alerts
                   (id, monitor_id, scan_id, place_id, alert_type, severity, title, detail, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $aid, $monitor['id'], $scanId, $a['place_id'],
                    $a['type'], $a['severity'], $a['title'],
                    $a['detail'] !== null ? json_encode($a['detail']) : null,
                ]
            );
            $this->fanoutNotification($monitor, $a);
        }

        // Finish scan + roll forward next_run_at
        $next = $this->nextRunAt($monitor['frequency']);
        $db->query(
            'UPDATE competitor_scans
             SET place_count = ?, new_count = ?, gone_count = ?, moved_count = ?,
                 rating_change_count = ?, finished_at = NOW()
             WHERE id = ?',
            [count($places), $newCount, $goneCount, $movedCount, $ratingChangeCount, $scanId]
        );
        $db->query(
            'UPDATE competitor_monitors
             SET last_run_at = NOW(), next_run_at = ?, updated_at = NOW()
             WHERE id = ?',
            [$next, $monitor['id']]
        );

        return [
            'scan_id' => $scanId,
            'monitor_id' => $monitor['id'],
            'place_count' => count($places),
            'new_count' => $newCount,
            'gone_count' => $goneCount,
            'moved_count' => $movedCount,
            'rating_change_count' => $ratingChangeCount,
            'alert_count' => count($alerts),
            'next_run_at' => $next,
        ];
    }

    private function resolveCenter(array $monitor): array
    {
        if (!empty($monitor['area_id'])) {
            // ST_Centroid is not implemented for geographic SRS in MySQL 8 —
            // ST_SRID(g, 0) relabels the geometry as planar so we can fall
            // back to center_lat/center_lng if those columns are populated.
            $row = Database::getInstance()->fetch(
                "SELECT center_lat, center_lng,
                        ST_X(ST_Centroid(ST_SRID(geometry, 0))) AS cx,
                        ST_Y(ST_Centroid(ST_SRID(geometry, 0))) AS cy
                 FROM areas WHERE id = ?",
                [$monitor['area_id']]
            );
            if ($row) {
                $lat = (float)($row['center_lat'] ?? $row['cy']);
                $lng = (float)($row['center_lng'] ?? $row['cx']);
                return [$lat, $lng, self::DEFAULT_RADIUS_M];
            }
        }
        $row = Database::getInstance()->fetch(
            'SELECT center_lat, center_lng FROM projects WHERE id = ?',
            [$monitor['project_id']]
        );
        return [
            (float)($row['center_lat'] ?? 38.9072),
            (float)($row['center_lng'] ?? -77.0369),
            self::DEFAULT_RADIUS_M,
        ];
    }

    private function fetchPlaces(float $lat, float $lng, int $radius, array $monitor): array
    {
        $types = json_decode($monitor['place_types'], true) ?: [];
        $keyword = $monitor['keywords'] ?? null;
        $svc = new GoogleMapsService();
        $merged = [];
        foreach ($types as $t) {
            try {
                $batch = $svc->searchPlacesNearby($lat, $lng, $radius, $t, $keyword);
                foreach ($batch as $p) {
                    if (!empty($p['id'])) $merged[$p['id']] = $p;
                }
            } catch (\Throwable $e) {
                error_log('CompetitorScanner places: ' . $e->getMessage());
            }
        }
        return array_values($merged);
    }

    private function nextRunAt(string $frequency): string
    {
        $intervals = ['daily' => '+1 day', 'weekly' => '+7 days', 'monthly' => '+30 days'];
        return date('Y-m-d H:i:s', strtotime($intervals[$frequency] ?? '+7 days'));
    }

    private function fanoutNotification(array $monitor, array $alert): void
    {
        try {
            $db = Database::getInstance();
            $project = $db->fetch('SELECT created_by FROM projects WHERE id = ?', [$monitor['project_id']]);
            $collabs = $db->fetchAll(
                'SELECT user_id FROM project_collaborators WHERE project_id = ?',
                [$monitor['project_id']]
            );
            $targets = array_column($collabs, 'user_id');
            if (!empty($project['created_by'])) $targets[] = $project['created_by'];
            $targets = array_values(array_unique($targets));
            // Don't fanout for "info" rating jumps — only warn/high
            if ($alert['severity'] === 'info' && $alert['type'] !== 'new') return;
            foreach ($targets as $uid) {
                $db->query(
                    'INSERT INTO notifications
                       (id, user_id, project_id, notif_type, title, body, payload_json, created_at)
                     VALUES (?, ?, ?, "competitor_alert", ?, ?, ?, NOW())',
                    [
                        Database::uuid(), $uid, $monitor['project_id'],
                        $alert['title'],
                        $monitor['name'] . ' · ' . $alert['type'],
                        json_encode(['monitor_id' => $monitor['id'], 'alert' => $alert]),
                    ]
                );
            }
        } catch (\Throwable $e) {
            error_log('competitor fanout: ' . $e->getMessage());
        }
    }

    private static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $R * $c;
    }
}
