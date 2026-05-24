<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Embed builder. Stores per-embed configuration (which areas to show,
 * dimensions, branding) and mints stable embed tokens so customers can
 * paste an iframe snippet onto their own site.
 *
 * Routes (auth except show/render which are public via embed_token):
 *   GET    /api/projects/{projectId}/embeds
 *   POST   /api/projects/{projectId}/embeds
 *   PUT    /api/embeds/{id}
 *   DELETE /api/embeds/{id}
 *   GET    /e/{token}              (public — renders an HTML iframe page;
 *                                   the actual route lives in the existing
 *                                   PublicShareController to keep all the
 *                                   public renderers in one place)
 */
class EmbedController
{
    public function index(Request $request): void
    {
        $project = $this->verifyProject($request);
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, embed_token, config_json, view_count, show_branding, created_at
               FROM embeds WHERE project_id = ? ORDER BY created_at DESC',
            [$project['id']]
        );
        foreach ($rows as &$r) {
            $r['config'] = json_decode($r['config_json'] ?? '{}', true);
            unset($r['config_json']);
            $r['embed_url'] = self::baseUrl() . '/embed/' . $r['embed_token'];
            $r['snippet']   = '<iframe src="' . htmlspecialchars($r['embed_url'], ENT_QUOTES) . '" '
                            . 'width="100%" height="500" frameborder="0" allow="geolocation"></iframe>';
        }
        Response::success(['embeds' => $rows]);
    }

    public function create(Request $request): void
    {
        $project = $this->verifyProject($request);
        $b = $request->getBody() ?? [];
        $config = is_array($b['config'] ?? null) ? $b['config'] : [];
        $config['width']  = (int)($config['width'] ?? 600);
        $config['height'] = (int)($config['height'] ?? 400);
        $config['show_legend']   = (bool)($config['show_legend'] ?? true);
        $config['show_controls'] = (bool)($config['show_controls'] ?? true);

        $id = Database::uuid();
        $token = bin2hex(random_bytes(12));
        Database::getInstance()->query(
            'INSERT INTO embeds (id, organization_id, project_id, embed_token, config_json,
                                 view_count, show_branding, created_at)
             VALUES (?, ?, ?, ?, ?, 0, ?, NOW())',
            [
                $id, $request->user['organization_id'], $project['id'], $token,
                json_encode($config),
                isset($b['show_branding']) ? ($b['show_branding'] ? 1 : 0) : 1,
            ]
        );
        Response::success([
            'id' => $id,
            'embed_token' => $token,
            'embed_url' => self::baseUrl() . '/embed/' . $token,
            'snippet' => '<iframe src="' . self::baseUrl() . '/embed/' . $token . '" width="' .
                $config['width'] . '" height="' . $config['height'] . '" frameborder="0"></iframe>',
        ], 'Embed created', 201);
    }

    public function update(Request $request): void
    {
        $id = $request->getParam('id');
        $this->loadOwned($request, $id);
        $b = $request->getBody() ?? [];
        $fields = []; $params = [];
        if (array_key_exists('config', $b))         { $fields[] = 'config_json = ?';   $params[] = json_encode($b['config']); }
        if (array_key_exists('show_branding', $b))  { $fields[] = 'show_branding = ?'; $params[] = $b['show_branding'] ? 1 : 0; }
        if (!$fields) Response::error('Nothing to update', 422);
        $params[] = $id;
        Database::getInstance()->query('UPDATE embeds SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
        Response::success([]);
    }

    public function destroy(Request $request): void
    {
        $id = $request->getParam('id');
        $this->loadOwned($request, $id);
        Database::getInstance()->query('DELETE FROM embeds WHERE id = ?', [$id]);
        Response::success([]);
    }

    private function verifyProject(Request $r): array
    {
        $p = Database::getInstance()->fetch(
            'SELECT id, organization_id FROM projects WHERE id = ?',
            [$r->getParam('projectId')]
        );
        if (!$p) Response::error('Project not found', 404);
        if ($p['organization_id'] !== $r->user['organization_id']) {
            Response::error('Access denied', 403);
        }
        return $p;
    }

    private function loadOwned(Request $r, string $id): array
    {
        $row = Database::getInstance()->fetch(
            'SELECT * FROM embeds WHERE id = ? AND organization_id = ?',
            [$id, $r->user['organization_id']]
        );
        if (!$row) Response::error('Embed not found', 404);
        return $row;
    }

    private static function baseUrl(): string
    {
        $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'https';
        $host = $_SERVER['HTTP_HOST'] ?? 'smappen.mygreendock.com';
        return $scheme . '://' . $host;
    }
}
