<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * First-run wizard + onboarding plumbing:
 *
 *   POST /api/onboarding/use-case      stores `users.use_case`
 *   POST /api/onboarding/seen          stamps `onboarding_flags.{flag}=true`
 *   GET  /api/onboarding/state         returns the user's full flag state
 *   POST /api/onboarding/clone-sample  copies the "Demo: Downtown Chicago" project into the caller's workspace
 *   POST /api/onboarding/activate      stamps the appropriate activation_metrics column
 *
 * The wizard frontend hits these in sequence:
 *   1. Modal opens → POST /use-case after step 1
 *   2. Step 2 sets center + creates first area
 *   3. Step 3 shows demographics — POST /seen with flag='wizard_complete'
 */
class OnboardingController
{
    public function setUseCase(Request $request): void
    {
        $b = $request->getBody() ?? [];
        $useCase = $b['use_case'] ?? null;
        if (!in_array($useCase, ['franchise', 'sales_territory', 'site_selection', 'delivery_zone', 'other'], true)) {
            Response::error('Invalid use_case', 422);
        }
        Database::getInstance()->query(
            'UPDATE users SET use_case = ? WHERE id = ?',
            [$useCase, $request->user['id']]
        );
        Response::success(['use_case' => $useCase]);
    }

    public function markSeen(Request $request): void
    {
        $b = $request->getBody() ?? [];
        $flag = $b['flag'] ?? null;
        if (!is_string($flag) || $flag === '' || mb_strlen($flag) > 60) {
            Response::error('flag (string ≤60 chars) required', 422);
        }
        $db = Database::getInstance();
        $row = $db->fetch('SELECT onboarding_flags FROM users WHERE id = ?', [$request->user['id']]);
        $flags = $row && $row['onboarding_flags'] ? json_decode($row['onboarding_flags'], true) : [];
        if (!is_array($flags)) $flags = [];
        $flags[$flag] = true;
        $db->query('UPDATE users SET onboarding_flags = ? WHERE id = ?',
            [json_encode($flags), $request->user['id']]);
        Response::success(['flags' => $flags]);
    }

    public function state(Request $request): void
    {
        $row = Database::getInstance()->fetch(
            'SELECT onboarding_flags, use_case, signed_up_at FROM users WHERE id = ?',
            [$request->user['id']]
        );
        $flags = $row && $row['onboarding_flags'] ? json_decode($row['onboarding_flags'], true) : [];
        if (!is_array($flags)) $flags = [];
        Response::success([
            'flags' => $flags,
            'use_case' => $row['use_case'] ?? null,
            'signed_up_at' => $row['signed_up_at'] ?? null,
        ]);
    }

    /**
     * Clone the system-wide "is_sample" project (created via seed script)
     * into the caller's workspace. Copies project + folders + areas, NOT
     * shared assets like demographics_cache (those re-compute on first open).
     */
    public function cloneSample(Request $request): void
    {
        $db = Database::getInstance();
        $org = $request->user['organization_id'];

        $sample = $db->fetch('SELECT id, name, description FROM projects WHERE is_sample = 1 LIMIT 1');
        if (!$sample) {
            Response::error('No sample project configured on this instance', 404);
        }

        $newId = Database::uuid();
        $db->query(
            'INSERT INTO projects (id, organization_id, name, description, center_lat, center_lng, zoom_level, created_at, updated_at)
             SELECT ?, ?, CONCAT("Demo · ", name), description, center_lat, center_lng, zoom_level, NOW(), NOW()
               FROM projects WHERE id = ?',
            [$newId, $org, $sample['id']]
        );

        // Clone folders (preserve nesting via temp id-map)
        $folders = $db->fetchAll('SELECT * FROM folders WHERE project_id = ?', [$sample['id']]);
        $idMap = [];
        foreach ($folders as $f) {
            $fid = Database::uuid();
            $idMap[$f['id']] = $fid;
        }
        foreach ($folders as $f) {
            $db->query(
                'INSERT INTO folders (id, project_id, name, color, sort_order, parent_folder_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$idMap[$f['id']], $newId, $f['name'], $f['color'], $f['sort_order'],
                 $f['parent_folder_id'] ? ($idMap[$f['parent_folder_id']] ?? null) : null]
            );
        }

        // Clone areas with their geometries
        $areas = $db->fetchAll(
            'SELECT id, name, area_type, ST_AsText(geometry) AS wkt, fill_color, stroke_color,
                    fill_opacity, stroke_weight, center_lat, center_lng, center_address,
                    travel_mode, travel_time_minutes, travel_distance_km, notes, folder_id
               FROM areas WHERE project_id = ?',
            [$sample['id']]
        );
        foreach ($areas as $a) {
            $db->query(
                'INSERT INTO areas (id, project_id, name, area_type, geometry, fill_color, stroke_color,
                                    fill_opacity, stroke_weight, center_lat, center_lng, center_address,
                                    travel_mode, travel_time_minutes, travel_distance_km, notes, folder_id,
                                    created_at, updated_at)
                 VALUES (?, ?, ?, ?, ST_GeomFromText(?, 4326), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [Database::uuid(), $newId, $a['name'], $a['area_type'], $a['wkt'],
                 $a['fill_color'], $a['stroke_color'], $a['fill_opacity'], $a['stroke_weight'],
                 $a['center_lat'], $a['center_lng'], $a['center_address'],
                 $a['travel_mode'], $a['travel_time_minutes'], $a['travel_distance_km'],
                 $a['notes'], $a['folder_id'] ? ($idMap[$a['folder_id']] ?? null) : null]
            );
        }

        Response::success(['project_id' => $newId, 'cloned_areas' => count($areas)]);
    }

    /**
     * Stamp the appropriate activation_metrics column. Called from
     * controllers that complete a funnel step (AreaController::store,
     * DemographicsController::show, ReportController::generate, etc.).
     * Idempotent — only writes if the column is currently NULL.
     */
    public function activate(Request $request): void
    {
        $b = $request->getBody() ?? [];
        $step = $b['step'] ?? null;
        $col = [
            'first_area'                    => 'first_area_at',
            'first_demographic'             => 'first_demographic_at',
            'first_export'                  => 'first_export_at',
            'first_share'                   => 'first_share_at',
            'first_report'                  => 'first_report_at',
            // Carafe milestones (added in migration 021)
            'first_pos_connected'           => 'first_pos_connected_at',
            'first_menu_synced'             => 'first_menu_synced_at',
            'first_recommendation_accepted' => 'first_recommendation_accepted_at',
            'first_dollar_measured'         => 'first_dollar_measured_at',
        ][$step] ?? null;
        if (!$col) Response::error('Invalid step', 422);
        self::stampActivation($request->user['id'], $request->user['organization_id'], $col);
        Response::success([]);
    }

    /**
     * Called from any controller to record an activation milestone. Safe to
     * spam — uses INSERT … ON DUPLICATE KEY UPDATE with COALESCE so the
     * first timestamp wins.
     */
    public static function stampActivation(string $userId, string $orgId, string $col): void
    {
        try {
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO activation_metrics (user_id, organization_id, signed_up_at, $col)
                 VALUES (?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE $col = COALESCE($col, VALUES($col))",
                [$userId, $orgId]
            );
        } catch (\Throwable $e) {
            error_log('stampActivation failed: ' . $e->getMessage());
        }
    }
}
