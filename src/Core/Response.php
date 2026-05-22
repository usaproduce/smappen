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

    public static function corsHeaders(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        // Only echo the origin back if it matches our allow-list.
        // Defaults to FRONTEND_URL plus its http variant (handy in dev).
        $configured = \App\Core\Config::get('FRONTEND_URL', '');
        $appUrl = \App\Core\Config::get('APP_URL', '');
        $allowed = array_filter([$configured, $appUrl, 'http://localhost:5173', 'http://localhost:8080']);
        $isAllowed = $origin !== '' && in_array($origin, $allowed, true);

        if ($isAllowed) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Credentials: true');
        } else {
            // Same-origin & token-bearer requests work without these.
            // Cross-origin requests from unapproved hosts get blocked by the browser.
            header('Access-Control-Allow-Origin: ' . ($configured ?: '*'));
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With, X-CSRF-Token, Stripe-Signature');
        header('Access-Control-Max-Age: 86400');
    }
}
