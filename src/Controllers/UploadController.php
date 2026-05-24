<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\StorageService;

/**
 * Generic file upload endpoint used by the field-note photo capture flow.
 * Stays small + multi-purpose so future surfaces (project logos, area photos,
 * profile avatars) can reuse it.
 *
 *   POST /api/uploads
 *     multipart/form-data — single `file` field
 *     query: ?kind=field_note_photo (optional, namespaces storage path)
 *
 * Returns: { url, key, size_bytes, mime }
 */
class UploadController
{
    private const MAX_BYTES = 8 * 1024 * 1024; // 8MB
    private const ALLOWED_MIME = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic',
    ];

    public function upload(Request $request): void
    {
        $file = $request->getFile('file');
        if (!$file || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            Response::error('No file uploaded', 400);
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            Response::error('File must be under 8MB', 413);
        }
        $mime = mime_content_type($file['tmp_name']) ?: '';
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            Response::error('Unsupported file type (' . $mime . ')', 415);
        }

        $kind = preg_replace('/[^a-z0-9_-]/', '', (string) $request->getQuery('kind', 'upload'));
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'bin');
        $key = "uploads/{$kind}/" . $request->user['organization_id'] . '/' . date('Y/m')
             . '/' . bin2hex(random_bytes(16)) . '.' . $ext;

        $contents = file_get_contents($file['tmp_name']);
        if ($contents === false) Response::error('Could not read upload', 500);

        try {
            $url = StorageService::put($key, $contents, $mime, /* public: */ true);
        } catch (\Throwable $e) {
            error_log('upload put failed: ' . $e->getMessage());
            Response::error('Storage failed', 500);
        }
        Response::success([
            'url' => $url,
            'key' => $key,
            'size_bytes' => $size,
            'mime' => $mime,
        ]);
    }
}
