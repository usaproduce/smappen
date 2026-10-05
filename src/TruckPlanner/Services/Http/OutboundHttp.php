<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Http;

/**
 * The one door out of Truck Planner. Every upstream call of the backend goes through request(), and this
 * is the only file under src/ that uses cURL.
 *
 * HTTPS only, to the five hosts of DECISIONS section 0 and to nothing else; redirects are not followed;
 * both timeouts are the caller's and are short. The answer is a tuple and the method does not throw for
 * anything an upstream or the network can do. It never returns or logs cURL's error text or the URL: a URL
 * can carry a key.
 *
 * Tests replace this class with a subclass that returns queued tuples.
 */
class OutboundHttp
{
    /** @var list<string> */
    public const ALLOWED_HOSTS = [
        'routes.googleapis.com',
        'maps.googleapis.com',
        'places.googleapis.com',
        'api.weather.gov',
        'api.eia.gov',
    ];

    public const ERROR_HOST = 'host_not_listed';
    public const ERROR_TIMEOUT = 'timeout';
    public const ERROR_CONNECT = 'connect';

    /** Answers larger than this are cut off and reported as a transfer error. */
    private const MAX_BODY_BYTES = 8388608;

    /**
     * @param string $method "GET" or "POST"
     * @param list<string> $headers request header lines ("Name: value")
     * @param string|null $body request body, or null for none
     * @return array{0: int, 1: string, 2: int, 3: ?string, 4: array<string, string>}
     *         [status, body, latency in ms, error code, response headers]. `status` is 0 when no HTTP
     *         answer arrived. The error code is null for a 2xx answer, else `host_not_listed` (nothing was
     *         sent), `timeout`, `connect`, `curl_<n>` or `http_<status>`. The headers map lower-cased
     *         names to their last value.
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $connectTimeoutS,
        int $timeoutS
    ): array {
        if (!self::isAllowedUrl($url)) {
            return [0, '', 0, self::ERROR_HOST, []];
        }

        $received = '';
        $responseHeaders = [];
        $handle = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => array_values($headers),
            CURLOPT_CONNECTTIMEOUT => max(1, $connectTimeoutS),
            CURLOPT_TIMEOUT => max(1, $timeoutS),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $colon = strpos($line, ':');
                if ($colon !== false && $colon > 0) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$received): int {
                if (strlen($received) + strlen($chunk) > self::MAX_BODY_BYTES) {
                    return 0;                     // stops the transfer: reported as a cURL error
                }
                $received .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);

        $started = microtime(true);
        curl_exec($handle);
        $latencyMs = (int) ((microtime(true) - $started) * 1000.0);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle);

        if ($errno !== 0) {
            return [0, '', $latencyMs, self::transportError($errno), []];
        }
        $error = ($status >= 200 && $status < 300) ? null : 'http_' . $status;
        return [$status, $received, $latencyMs, $error, $responseHeaders];
    }

    /**
     * Is this an address request() may call: https, one of ALLOWED_HOSTS, the default port, no credentials?
     */
    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }
        return in_array(strtolower($parts['host']), self::ALLOWED_HOSTS, true);
    }

    private static function transportError(int $errno): string
    {
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            return self::ERROR_TIMEOUT;
        }
        if (in_array($errno, [CURLE_COULDNT_RESOLVE_PROXY, CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_SSL_CONNECT_ERROR], true)) {
            return self::ERROR_CONNECT;
        }
        return 'curl_' . $errno;
    }
}
