<?php
namespace App\Core;

class Request
{
    private string $method;
    private string $path;
    private array $query;
    private ?array $body;
    private array $headers;
    private array $params = [];
    public ?array $user = null;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $this->query = $_GET;
        $this->headers = $this->parseHeaders();

        $raw = file_get_contents('php://input');
        $contentType = $this->headers['Content-Type'] ?? '';
        if (stripos($contentType, 'application/json') !== false && $raw !== '' && $raw !== false) {
            $decoded = json_decode($raw, true);
            $this->body = is_array($decoded) ? $decoded : null;
        } else {
            $this->body = $_POST ?: null;
        }
    }

    private function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
                $headers[$name] = $v;
            } elseif (in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $k))));
                $headers[$name] = $v;
            }
        }
        return $headers;
    }

    public function getMethod(): string { return $this->method; }
    public function getPath(): string { return $this->path; }
    public function getBody(): ?array { return $this->body; }
    public function getQuery(?string $name = null, $default = null)
    {
        if ($name === null) return $this->query;
        return $this->query[$name] ?? $default;
    }
    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? $this->headers[ucwords(strtolower($name), '-')] ?? null;
    }
    public function input(string $name, $default = null)
    {
        return $this->body[$name] ?? $this->query[$name] ?? $default;
    }
    public function setParams(array $params): void { $this->params = $params; }
    public function getParam(string $name, $default = null) { return $this->params[$name] ?? $default; }
    public function getParams(): array { return $this->params; }
    public function getBearerToken(): ?string
    {
        $auth = $this->getHeader('Authorization');
        if ($auth && preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }
    public function getFile(string $name): ?array
    {
        return $_FILES[$name] ?? null;
    }
}
