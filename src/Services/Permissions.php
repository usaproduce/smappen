<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * #14 — Per-area / per-folder permission checks.
 *
 * Default model (preserved): if no `area_permissions` row exists for an
 * area, anyone in the owning organization can read AND write it. Same
 * for folders. Rows in those tables OVERRIDE the default with explicit
 * grants — useful for "Adam owns East coast, Sarah owns West, but both
 * can read each other's."
 *
 * Roles (most → least powerful):
 *   • owner  — read, write, delete, grant
 *   • editor — read, write
 *   • viewer — read only
 *
 * Org admins always have effective `owner` regardless of grants.
 */
class Permissions
{
    public const ROLE_OWNER  = 'owner';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_VIEWER = 'viewer';

    public static function canReadArea(array $user, string $areaId): bool
    {
        return self::resolveArea($user, $areaId) !== null;
    }

    public static function canWriteArea(array $user, string $areaId): bool
    {
        $r = self::resolveArea($user, $areaId);
        return $r === self::ROLE_OWNER || $r === self::ROLE_EDITOR;
    }

    public static function canDeleteArea(array $user, string $areaId): bool
    {
        $r = self::resolveArea($user, $areaId);
        return $r === self::ROLE_OWNER;
    }

    /**
     * Returns the effective role string ('owner'|'editor'|'viewer') the
     * user has on this area, or null if no access at all. Resolution
     * order: org admin → explicit area grant → folder grant → org default.
     */
    private static function resolveArea(array $user, string $areaId): ?string
    {
        $db = Database::getInstance();
        $row = $db->fetch(
            'SELECT a.folder_id, p.organization_id
               FROM areas a
               JOIN projects p ON p.id = a.project_id
              WHERE a.id = ?',
            [$areaId]
        );
        if (!$row) return null;
        if (($row['organization_id'] ?? null) !== ($user['organization_id'] ?? null)) return null;

        // Org admin → always owner.
        if (in_array(($user['account_type'] ?? $user['role'] ?? ''), ['admin', 'owner'], true)) {
            return self::ROLE_OWNER;
        }

        // Explicit area grant beats folder grant beats default.
        $explicit = $db->fetch(
            'SELECT role FROM area_permissions WHERE area_id = ? AND user_id = ?',
            [$areaId, $user['id']]
        );
        if ($explicit) return (string)$explicit['role'];

        if ($row['folder_id']) {
            $folder = $db->fetch(
                'SELECT role FROM folder_permissions WHERE folder_id = ? AND user_id = ?',
                [$row['folder_id'], $user['id']]
            );
            if ($folder) return (string)$folder['role'];
        }

        // Default: org-wide editor (matches pre-#14 behavior).
        return self::ROLE_EDITOR;
    }
}
