<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Field notes — geo-stamped observations from the mobile PWA.
 *  GET  /api/projects/{projectId}/field-notes               list (optionally bbox-filtered)
 *  POST /api/projects/{projectId}/field-notes               create
 *  DEL  /api/field-notes/{id}                               delete (author or editor+)
 *  GET  /api/projects/{projectId}/where-am-i?lat=&lng=      area + tract identifying the location
 */
class FieldNoteController
{
    public function index(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        self::requireProject($request, $projectId);
        $bbox = $request->getQuery('bbox');
        $params = [$projectId];
        $sql = "SELECT fn.id, fn.area_id, fn.body, fn.lat, fn.lng, fn.accuracy_m,
                       fn.photo_url, fn.tags, fn.captured_at, fn.created_at,
                       u.name AS author_name
                FROM field_notes fn
                LEFT JOIN users u ON u.id = fn.user_id
                WHERE fn.project_id = ?";
        if ($bbox) {
            $parts = array_map('floatval', explode(',', $bbox));
            if (count($parts) === 4) {
                [$minLng, $minLat, $maxLng, $maxLat] = $parts;
                $sql .= " AND fn.lat BETWEEN ? AND ? AND fn.lng BETWEEN ? AND ?";
                $params[] = $minLat;
                $params[] = $maxLat;
                $params[] = $minLng;
                $params[] = $maxLng;
            }
        }
        $sql .= ' ORDER BY fn.captured_at DESC LIMIT 500';
        $rows = Database::getInstance()->fetchAll($sql, $params);
        foreach ($rows as &$r) {
            if (!empty($r['tags'])) $r['tags'] = json_decode($r['tags'], true);
        }
        Response::success(['field_notes' => $rows]);
    }

    public function create(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        self::requireProject($request, $projectId);
        $body = $request->getBody() ?? [];
        $lat = (float)($body['lat'] ?? 0);
        $lng = (float)($body['lng'] ?? 0);
        $note = trim((string)($body['body'] ?? ''));
        if (mb_strlen($note) < 1 || mb_strlen($note) > 5000) Response::error('body must be 1-5000 chars');
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) Response::error('Invalid coordinates');

        // Accept anything strtotime() understands (ISO 8601, RFC 2822, "now",
        // unix timestamps). Bad/garbage input falls back to "now" instead of
        // throwing — the PWA outbox replays old captures so timestamps must
        // be lenient.
        $rawCaptured = $body['captured_at'] ?? null;
        $captured = date('Y-m-d H:i:s');
        if (is_string($rawCaptured) && $rawCaptured !== '') {
            $ts = strtotime($rawCaptured);
            if ($ts !== false && $ts > 0) {
                $captured = date('Y-m-d H:i:s', $ts);
            }
        }

        $id = Database::uuid();
        Database::getInstance()->query(
            'INSERT INTO field_notes
               (id, project_id, area_id, user_id, body, lat, lng, location,
                accuracy_m, photo_url, tags, captured_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ST_GeomFromText(?, 4326), ?, ?, ?, ?, NOW())',
            [
                $id, $projectId,
                $body['area_id'] ?? null,
                $request->user['id'],
                $note,
                $lat, $lng,
                "POINT({$lat} {$lng})",
                isset($body['accuracy_m']) ? (float)$body['accuracy_m'] : null,
                $body['photo_url'] ?? null,
                isset($body['tags']) ? json_encode((array)$body['tags']) : null,
                $captured,
            ]
        );
        Response::success(['id' => $id]);
    }

    public function destroy(Request $request): void
    {
        $id = $request->getParam('id');
        $row = Database::getInstance()->fetch(
            "SELECT fn.user_id, fn.project_id, p.organization_id
             FROM field_notes fn
             JOIN projects p ON p.id = fn.project_id
             WHERE fn.id = ?",
            [$id]
        );
        if (!$row) Response::error('Note not found', 404);
        if ($row['organization_id'] !== $request->user['organization_id']) {
            // Author can still delete their own even across orgs (unlikely)
            if ($row['user_id'] !== $request->user['id']) Response::error('Access denied', 403);
        }
        Database::getInstance()->query('DELETE FROM field_notes WHERE id = ?', [$id]);
        Response::success(['id' => $id, 'deleted' => true]);
    }

    public function whereAmI(Request $request): void
    {
        $projectId = $request->getParam('projectId');
        self::requireProject($request, $projectId);
        $lat = (float)$request->getQuery('lat');
        $lng = (float)$request->getQuery('lng');
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) Response::error('Invalid coordinates');

        // MySQL 8 SRID 4326 = (lat lng) axis order; emit lat first.
        $point = "POINT({$lat} {$lng})";
        // Areas containing this point in the project
        $areas = Database::getInstance()->fetchAll(
            "SELECT id, name, fill_color, area_type
             FROM areas
             WHERE project_id = ?
               AND ST_Contains(geometry, ST_GeomFromText(?, 4326))",
            [$projectId, $point]
        );
        // Containing tract + its demographics
        $tract = Database::getInstance()->fetch(
            "SELECT ct.geoid, ct.name,
                    d.total_population, d.median_household_income,
                    d.median_home_value, ts.segment_id, ts.segment_name
             FROM census_tracts ct
             LEFT JOIN census_demographics d ON d.geoid = ct.geoid
             LEFT JOIN tract_segments ts ON ts.geoid = ct.geoid
             WHERE ST_Contains(ct.geometry, ST_GeomFromText(?, 4326))
             LIMIT 1",
            [$point]
        );
        Response::success([
            'lat' => $lat,
            'lng' => $lng,
            'areas' => $areas,
            'tract' => $tract,
        ]);
    }

    private static function requireProject(Request $request, ?string $projectId): array
    {
        if (!$projectId) Response::error('projectId required');
        $p = Database::getInstance()->fetch(
            'SELECT id FROM projects WHERE id = ? AND organization_id = ?',
            [$projectId, $request->user['organization_id']]
        );
        if (!$p) {
            // Try collaborator access
            $pc = Database::getInstance()->fetch(
                'SELECT pc.* FROM project_collaborators pc
                 WHERE pc.project_id = ? AND pc.user_id = ?',
                [$projectId, $request->user['id']]
            );
            if (!$pc) Response::error('Project not found', 404);
            return ['id' => $projectId];
        }
        return $p;
    }
}
