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

    /**
     * Clear every onboarding flag for the caller — reopens the first-run
     * wizards on next page-load. Used by the "Reset onboarding" button in
     * profile settings. Doesn't touch use_case or activation_metrics:
     * those are funnel events, not dismissable prompts.
     */
    public function reset(Request $request): void
    {
        Database::getInstance()->query(
            'UPDATE users SET onboarding_flags = NULL WHERE id = ?',
            [$request->user['id']]
        );
        Response::success(['flags' => []], 'Onboarding reset — wizards will re-appear on next load');
    }

    public function state(Request $request): void
    {
        $db = Database::getInstance();
        $row = $db->fetch(
            'SELECT onboarding_flags, onboarding_state, use_case, signed_up_at, organization_id, created_at
               FROM users WHERE id = ?',
            [$request->user['id']]
        );
        $flags = $row && $row['onboarding_flags'] ? json_decode($row['onboarding_flags'], true) : [];
        if (!is_array($flags)) $flags = [];
        $state = $row && $row['onboarding_state'] ? json_decode($row['onboarding_state'], true) : [];
        if (!is_array($state)) $state = [];

        // Signal #19: invitee detection. If this user is not the org's first
        // user, the wizard copy should acknowledge team context.
        // Signal #19/gate: org's current restaurant count. Lets the frontend
        // gate the wizard correctly even on shared workspaces.
        $orgId = (string) ($row['organization_id'] ?? '');
        $isFirstUser = true;
        $orgRestaurantCount = 0;
        if ($orgId !== '') {
            $firstUserRow = $db->fetch(
                'SELECT id FROM users WHERE organization_id = ? ORDER BY created_at ASC LIMIT 1',
                [$orgId]
            );
            $isFirstUser = $firstUserRow && (string) $firstUserRow['id'] === (string) $request->user['id'];
            $countRow = $db->fetch(
                'SELECT COUNT(*) AS n FROM restaurants WHERE organization_id = ? AND archived_at IS NULL',
                [$orgId]
            );
            $orgRestaurantCount = (int) ($countRow['n'] ?? 0);
        }

        Response::success([
            'flags'                => $flags,
            'wizard_state'         => $state,
            'use_case'             => $row['use_case'] ?? null,
            'signed_up_at'         => $row['signed_up_at'] ?? null,
            'is_org_first_user'    => $isFirstUser,
            'org_restaurant_count' => $orgRestaurantCount,
        ]);
    }

    /**
     * Save a wizard's resume state — opaque JSON, capped at 4KB so we don't
     * let the frontend stash huge blobs. One namespace per wizard ('carafe',
     * 'smappen', etc.) so they don't stomp each other.
     *
     *   POST /api/onboarding/wizard-state
     *   { "wizard": "carafe", "state": { "step": 3, "useCase": "existing", ... } }
     */
    public function saveWizardState(Request $request): void
    {
        $b = $request->getBody() ?? [];
        $wizard = $b['wizard'] ?? null;
        $payload = $b['state'] ?? null;
        if (!is_string($wizard) || !preg_match('/^[a-z_]{1,30}$/', $wizard)) {
            Response::error('wizard (a-z_ ≤30 chars) required', 422);
        }
        if (!is_array($payload)) {
            Response::error('state (object) required', 422);
        }
        $json = json_encode($payload);
        if (strlen($json) > 4096) {
            Response::error('state too large (>4KB)', 413);
        }

        $db = Database::getInstance();
        $row = $db->fetch('SELECT onboarding_state FROM users WHERE id = ?', [$request->user['id']]);
        $state = $row && $row['onboarding_state'] ? json_decode($row['onboarding_state'], true) : [];
        if (!is_array($state)) $state = [];
        $state[$wizard] = $payload;
        $db->query(
            'UPDATE users SET onboarding_state = ? WHERE id = ?',
            [json_encode($state), $request->user['id']]
        );
        Response::success(['wizard_state' => $state]);
    }

    /**
     * Atomic dismiss: stamp the flag, stamp activation_metrics.carafe_wizard_completed_at,
     * record the exit path, and clear any resume state for the wizard.
     *
     *   POST /api/onboarding/dismiss-wizard
     *   { "wizard": "carafe", "path": "completed_sample" }
     *
     * Valid paths (advisory — accepted as a free string capped at 40 chars):
     *   skipped_step_1, skipped_step_2, skipped_step_3,
     *   completed_sample, completed_real_manual, completed_real_pos
     */
    public function dismissWizard(Request $request): void
    {
        $b = $request->getBody() ?? [];
        $wizard = $b['wizard'] ?? null;
        $path   = $b['path']   ?? null;
        if (!is_string($wizard) || !preg_match('/^[a-z_]{1,30}$/', $wizard)) {
            Response::error('wizard (a-z_ ≤30 chars) required', 422);
        }
        if (!is_string($path) || $path === '' || mb_strlen($path) > 40) {
            Response::error('path (1-40 chars) required', 422);
        }

        $db = Database::getInstance();
        $row = $db->fetch('SELECT onboarding_flags, onboarding_state FROM users WHERE id = ?', [$request->user['id']]);
        $flags = $row && $row['onboarding_flags'] ? json_decode($row['onboarding_flags'], true) : [];
        if (!is_array($flags)) $flags = [];
        $state = $row && $row['onboarding_state'] ? json_decode($row['onboarding_state'], true) : [];
        if (!is_array($state)) $state = [];

        // Stamp the flag with metadata so the re-pop heuristic (#4) can
        // distinguish "completed" from "soft-skip 24h ago".
        $isCompletion = str_starts_with($path, 'completed_');
        $flagKey = $wizard . '_wizard_complete';
        $flags[$flagKey] = true;

        // Soft-skip bookkeeping: remember the dismissed-at + path so the
        // gate can pop once more after 24h on a non-completion exit.
        if (!$isCompletion) {
            $state[$wizard . '_dismissed_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $state[$wizard . '_dismissed_path'] = $path;
        } else {
            // Completion clears any resume state — we're done with this wizard.
            unset($state[$wizard]);
            unset($state[$wizard . '_dismissed_at']);
            unset($state[$wizard . '_dismissed_path']);
        }
        $db->query(
            'UPDATE users SET onboarding_flags = ?, onboarding_state = ? WHERE id = ?',
            [json_encode($flags), json_encode($state), $request->user['id']]
        );

        // Stamp activation metrics: completion stamps carafe_wizard_completed_at;
        // path goes into carafe_wizard_dismissed_path regardless (we want
        // funnel signal for both "completed_sample" AND "skipped_step_2").
        if ($wizard === 'carafe') {
            if ($isCompletion) {
                self::stampActivation($request->user['id'], $request->user['organization_id'], 'carafe_wizard_completed_at');
            }
            self::recordDismissPath($request->user['id'], $request->user['organization_id'], $path);
        }

        Response::success(['flags' => $flags, 'wizard_state' => $state]);
    }

    /** Idempotent insert+update of the dismiss-path column. */
    private static function recordDismissPath(string $userId, string $orgId, string $path): void
    {
        try {
            Database::getInstance()->query(
                'INSERT INTO activation_metrics (user_id, organization_id, signed_up_at, carafe_wizard_dismissed_path)
                 VALUES (?, ?, NOW(), ?)
                 ON DUPLICATE KEY UPDATE carafe_wizard_dismissed_path = VALUES(carafe_wizard_dismissed_path)',
                [$userId, $orgId, $path]
            );
        } catch (\Throwable $e) {
            error_log('recordDismissPath failed: ' . $e->getMessage());
        }
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
            // Carafe wizard per-step funnel (added in migration 037)
            'carafe_wizard_step_2'          => 'carafe_wizard_step_2_at',
            'carafe_wizard_step_3'          => 'carafe_wizard_step_3_at',
            'carafe_wizard_completed'       => 'carafe_wizard_completed_at',
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
