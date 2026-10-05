<?php
declare(strict_types=1);

/**
 * A step of the smoke test that did not hold. The message carries the request, the status and the body.
 */
final class SmokeFailure extends RuntimeException
{
}

/**
 * The HTTP client of the Truck Planner smoke test (docs/truck-planner/04_BACKEND.md 7.2).
 *
 * It talks plain HTTP to a server on this machine and to nothing else: any other host is refused when the
 * client is built. One request at a time (the development server handles one at a time).
 *
 *     $c->as(1)->post('/api/truck/spots', ['name' => 'Lot 4', 'point' => ['lat' => 38.96, 'lng' => -77.36]])
 *       ->status(201)->path('data.spot.name', 'Lot 4')->finite('data.spot.vectors.normal.nearby.*');
 *     $id = $c->value('data.spot.id');
 *     $c->as(2)->get('/api/truck/spots/' . $id)->status(404);
 *
 * Every response is checked before the step sees it. The client fails on a 401, on any 5xx, on an empty
 * body and on a body that is not the house envelope {"success": ..., "data" | "error": ...}. A step that
 * expects one of these says so first: allow(401), allow(503). Two routes do not answer with the envelope
 * when they succeed, the cell pack and the export: their bodies are taken as they come.
 *
 * Paths into the JSON are dotted: "data.plan.stops.0.kind". A "*" stands for every item of a list or
 * every value of an object.
 */
final class SmokeClient
{
    private const HOSTS = ['127.0.0.1', 'localhost'];

    /** Success bodies that are not the envelope: route 8 (binary pack) and route 42 (a bare JSON document). */
    private const RAW_PATHS = ['#^/api/truck/regions/[^/]+/pack/[^/]+$#', '#^/api/truck/export$#'];

    private string $base;

    /** @var array<int, string> user number => bearer token */
    private array $tokens = [];
    private int $user = 0;

    /** @var list<int> */
    private array $allowed = [];

    /** @var array<string, string> */
    private array $extraHeaders = [];

    private string $lastRequest = '(no request yet)';
    private int $lastStatus = 0;
    private string $lastBody = '';

    /** @var array<string, string> */
    private array $lastHeaders = [];

    /** @var array<int|string, mixed>|null */
    private ?array $lastJson = null;

    private int $requests = 0;

    public function __construct(string $baseUrl)
    {
        $parts = parse_url($baseUrl);
        $ok = is_array($parts)
            && ($parts['scheme'] ?? '') === 'http'
            && in_array(strtolower((string) ($parts['host'] ?? '')), self::HOSTS, true)
            && !isset($parts['user']) && !isset($parts['pass'])
            && (!isset($parts['path']) || $parts['path'] === '/')
            && !isset($parts['query']) && !isset($parts['fragment']);
        if (!$ok) {
            throw new InvalidArgumentException('the smoke test talks only to a plain-HTTP server on this machine');
        }
        // Rebuilt from the checked parts: scheme and host are exactly what was accepted above.
        $this->base = $parts['scheme'] . '://' . strtolower((string) $parts['host'])
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    // ------------------------------------------------------------------------------------ who is asking

    /** Remember the bearer token of a user. Users are numbered from 1. */
    public function setToken(int $user, string $token): self
    {
        if ($user < 1 || $token === '') {
            throw new InvalidArgumentException('users are numbered from 1 and have a token');
        }
        $this->tokens[$user] = $token;
        return $this;
    }

    /** Send the following requests as this user. 0 sends them without a token. */
    public function as(int $user): self
    {
        if ($user !== 0 && !isset($this->tokens[$user])) {
            throw new InvalidArgumentException('no token is known for user ' . $user);
        }
        $this->user = $user;
        return $this;
    }

    /** Statuses the next request may answer although the client would fail on them (401, 5xx). */
    public function allow(int ...$statuses): self
    {
        $this->allowed = array_values($statuses);
        return $this;
    }

    /** An extra header for the next request only ("If-None-Match", "Accept-Encoding"). */
    public function header(string $name, string $value): self
    {
        $this->extraHeaders[$name] = $value;
        return $this;
    }

    // ------------------------------------------------------------------------------------ requests

    /**
     * @param array<string, scalar> $query
     */
    public function get(string $path, array $query = []): self
    {
        return $this->send('GET', $path . ($query === [] ? '' : '?' . http_build_query($query)), null);
    }

    /**
     * @param array<int|string, mixed>|null $body a JSON object, or null for no body
     */
    public function post(string $path, ?array $body = null): self
    {
        return $this->send('POST', $path, $body);
    }

    /**
     * @param array<int|string, mixed>|null $body
     */
    public function put(string $path, ?array $body = null): self
    {
        return $this->send('PUT', $path, $body);
    }

    /**
     * @param array<int|string, mixed>|null $body
     */
    public function delete(string $path, ?array $body = null): self
    {
        return $this->send('DELETE', $path, $body);
    }

    /** A request whose body is sent exactly as given (for bodies that are not valid JSON objects). */
    public function sendRaw(string $method, string $path, string $body): self
    {
        return $this->transfer($method, $path, $body);
    }

    // ------------------------------------------------------------------------------------ assertions

    /** The last response had one of these statuses. */
    public function status(int ...$expected): self
    {
        if (!in_array($this->lastStatus, $expected, true)) {
            $this->fail('expected status ' . implode(' or ', $expected));
        }
        return $this;
    }

    /** The value at a JSON path equals `$expected`. Numbers compare by value (15 equals 15.0). */
    public function path(string $jsonPath, mixed $expected): self
    {
        $found = $this->resolve($jsonPath);
        if ($found === []) {
            $this->fail('nothing at ' . $jsonPath);
        }
        foreach ($found as $at => $actual) {
            if (!self::same($actual, $expected)) {
                $this->fail($at . ' is ' . self::show($actual) . ', expected ' . self::show($expected));
            }
        }
        return $this;
    }

    /** Every value at these JSON paths is a number: not null, not text. Each path must exist. */
    public function finite(string ...$jsonPaths): self
    {
        foreach ($jsonPaths as $jsonPath) {
            $found = $this->resolve($jsonPath);
            if ($found === []) {
                $this->fail('nothing at ' . $jsonPath);
            }
            foreach ($found as $at => $actual) {
                if (!is_int($actual) && !is_float($actual)) {
                    $this->fail($at . ' is ' . self::show($actual) . ' where a number is required');
                }
            }
        }
        return $this;
    }

    /** A check of the step's own: fails with the last request and response when `$holds` is false. */
    public function check(bool $holds, string $what): self
    {
        if (!$holds) {
            $this->fail($what);
        }
        return $this;
    }

    // ------------------------------------------------------------------------------------ reading the last response

    /** The value at a JSON path without "*", or null when there is none. */
    public function value(string $jsonPath): mixed
    {
        $found = $this->resolve($jsonPath);
        return $found === [] ? null : reset($found);
    }

    /**
     * @return array<int|string, mixed>|null the decoded body, null when it is not JSON
     */
    public function json(): ?array
    {
        return $this->lastJson;
    }

    /** The body as it arrived (not decompressed, not decoded). */
    public function body(): string
    {
        return $this->lastBody;
    }

    public function lastStatus(): int
    {
        return $this->lastStatus;
    }

    /** A response header by its name in any case, or null. */
    public function responseHeader(string $name): ?string
    {
        return $this->lastHeaders[strtolower($name)] ?? null;
    }

    public function requestCount(): int
    {
        return $this->requests;
    }

    // ------------------------------------------------------------------------------------ internals

    /**
     * @param array<int|string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body): self
    {
        $encoded = null;
        if ($body !== null) {
            // An empty PHP array would be sent as [], and the API wants an object.
            $encoded = json_encode($body === [] ? new stdClass() : $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                throw new InvalidArgumentException('the request body cannot be written as JSON');
            }
        }
        return $this->transfer($method, $path, $encoded);
    }

    private function transfer(string $method, string $path, ?string $body): self
    {
        if ($path === '' || $path[0] !== '/') {
            throw new InvalidArgumentException('a request path starts with /');
        }
        $headers = ['Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($this->user !== 0) {
            $headers[] = 'Authorization: Bearer ' . $this->tokens[$this->user];
        }
        foreach ($this->extraHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $allowed = $this->allowed;
        $this->allowed = [];
        $this->extraHeaders = [];

        $responseHeaders = [];
        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $this->base . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $colon = strpos($line, ':');
                if ($colon !== false && $colon > 0) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle);

        $this->requests++;
        $this->lastRequest = $method . ' ' . $path
            . ($this->user === 0 ? ' (no token)' : ' (user ' . $this->user . ')')
            . ($body === null ? '' : "\n  body: " . self::cut($body));
        $this->lastStatus = $status;
        $this->lastBody = is_string($response) ? $response : '';
        $this->lastHeaders = $responseHeaders;
        $decoded = $this->lastBody === '' ? null : json_decode($this->lastBody, true);
        $this->lastJson = is_array($decoded) ? $decoded : null;

        if ($errno !== 0 || $status === 0) {
            $this->fail('no answer from the server (cURL error ' . $errno . ')');
        }
        if (($status === 401 || $status >= 500) && !in_array($status, $allowed, true)) {
            $this->fail($status === 401 ? 'a 401 that the step did not expect' : 'a server error');
        }
        if ($status === 304 || $status === 204) {
            return $this;
        }
        if ($this->lastBody === '') {
            $this->fail('an empty body');
        }
        $bare = $status >= 200 && $status < 300 && self::isRawPath($path);
        if (!$bare && !self::isEnvelope($this->lastJson)) {
            $this->fail('the body is not the envelope {"success": ..., "data" or "error": ...}');
        }
        return $this;
    }

    private static function isRawPath(string $path): bool
    {
        $only = (string) parse_url($path, PHP_URL_PATH);
        foreach (self::RAW_PATHS as $pattern) {
            if (preg_match($pattern, $only) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int|string, mixed>|null $json
     */
    private static function isEnvelope(?array $json): bool
    {
        if ($json === null || !array_key_exists('success', $json) || !is_bool($json['success'])) {
            return false;
        }
        return $json['success'] ? array_key_exists('data', $json) : (is_string($json['error'] ?? null) && $json['error'] !== '');
    }

    /**
     * The values at a dotted path of the last JSON body, keyed by where they were found.
     *
     * @return array<string, mixed>
     */
    private function resolve(string $jsonPath): array
    {
        $current = ['' => $this->lastJson];
        foreach (explode('.', $jsonPath) as $segment) {
            $next = [];
            foreach ($current as $at => $node) {
                if (!is_array($node)) {
                    continue;
                }
                if ($segment === '*') {
                    foreach ($node as $key => $child) {
                        $next[ltrim($at . '.' . $key, '.')] = $child;
                    }
                } elseif (array_key_exists($segment, $node)) {
                    $next[ltrim($at . '.' . $segment, '.')] = $node[$segment];
                }
            }
            $current = $next;
        }
        return $current;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        $aNumber = is_int($a) || is_float($a);
        $bNumber = is_int($b) || is_float($b);
        if ($aNumber && $bNumber) {
            return (float) $a === (float) $b;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!array_key_exists($key, $b) || !self::same($value, $b[$key])) {
                    return false;
                }
            }
            return true;
        }
        return $a === $b;
    }

    private static function show(mixed $value): string
    {
        $text = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return self::cut($text === false ? '(unprintable)' : $text);
    }

    private static function cut(string $text): string
    {
        return strlen($text) > 600 ? substr($text, 0, 600) . ' ... (' . strlen($text) . ' bytes)' : $text;
    }

    private function fail(string $why): never
    {
        $printable = preg_match('//u', $this->lastBody) === 1 ? $this->lastBody : '(' . strlen($this->lastBody) . ' bytes, not text)';
        throw new SmokeFailure(
            $why . "\n  request: " . $this->lastRequest
            . "\n  status: " . $this->lastStatus
            . "\n  body: " . self::cut($printable)
        );
    }
}
