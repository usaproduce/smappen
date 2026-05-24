<?php
namespace App\Core;

class Response
{
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        self::corsHeaders();
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success($data = [], ?string $message = null, int $status = 200): void
    {
        $payload = ['success' => true, 'data' => $data];
        if ($message !== null) $payload['message'] = $message;
        self::json($payload, $status);
    }

    public static function error(string $message, int $status = 400, $details = null): void
    {
        $payload = ['success' => false, 'error' => $message];
        if ($details !== null) $payload['details'] = $details;
        self::json($payload, $status);
    }

    public static function paginated(array $data, int $total, int $page, int $perPage): void
    {
        self::json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ]);
    }

    public static function file(string $path, string $filename, string $contentType = 'application/octet-stream'): void
    {
        if (!file_exists($path)) {
            self::error('File not found', 404);
        }
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    /**
     * Hint to browsers (and CDNs) that this response is cacheable. Call BEFORE
     * Response::success / Response::json. Pass null/0 for no-store on mutating
     * endpoints. The Vary header is set so cached responses aren't shared
     * across users.
     */
    public static function cacheable(?int $maxAgeSeconds, bool $public = false): void
    {
        if ($maxAgeSeconds === null || $maxAgeSeconds <= 0) {
            header('Cache-Control: no-store');
            return;
        }
        $scope = $public ? 'public' : 'private';
        header('Cache-Control: ' . $scope . ', max-age=' . $maxAgeSeconds);
        header('Vary: Authorization, Origin');
    }

    public static function corsHeaders(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $cfg = self::corsConfig();
        $allowed = $cfg['allowed_origins'];
        $isAllowed = $origin !== '' && (in_array('*', $allowed, true) || in_array($origin, $allowed, true));

        if ($isAllowed && $origin !== '') {
            // Always echo the specific origin so Access-Control-Allow-Credentials works
            // (browsers reject "*" with credentials).
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            if (!empty($cfg['allow_credentials'])) {
                header('Access-Control-Allow-Credentials: true');
            }
        } elseif ($origin === '') {
            // Same-origin (no Origin header) — no ACAO needed; skip silently.
        } else {
            // Disallowed cross-origin: don't echo back. The browser blocks the response.
            // We still need to emit the method/header allowlists so the preflight
            // returns 204 cleanly (browser will reject the actual request).
        }
        header('Access-Control-Allow-Methods: ' . implode(', ', $cfg['allowed_methods']));
        header('Access-Control-Allow-Headers: ' . implode(', ', $cfg['allowed_headers']));
        header('Access-Control-Max-Age: ' . (int) $cfg['max_age']);
    }

    private static array $corsCache = [];
    private static function corsConfig(): array
    {
        if (!empty(self::$corsCache)) return self::$corsCache;
        $file = dirname(__DIR__, 2) . '/config/cors.php';
        if (file_exists($file)) {
            $cfg = require $file;
            if (is_array($cfg)) {
                self::$corsCache = $cfg + self::corsDefaults();
                return self::$corsCache;
            }
        }
        self::$corsCache = self::corsDefaults();
        return self::$corsCache;
    }
    private static function corsDefaults(): array
    {
        return [
            'allowed_origins' => ['https://smappen.mygreendock.com', 'http://localhost:5173', 'http://localhost:8080'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'],
            'allowed_headers' => ['Authorization', 'Content-Type', 'X-Requested-With', 'X-CSRF-Token', 'Stripe-Signature'],
            'max_age' => 86400,
            'allow_credentials' => true,
        ];
    }
}
