<?php
namespace App\Services;

use App\Core\Config;

/**
 * Object-storage abstraction (#25). Two drivers:
 *   - "local" (default): writes to storage/uploads or storage/exports on disk
 *   - "spaces": uploads to a DigitalOcean Spaces (S3-compatible) bucket
 *
 * No external SDK required for the Spaces driver — we sign requests
 * inline. Falling back to the local driver if SPACES_BUCKET isn't configured
 * means the app keeps working in dev without any S3 setup.
 *
 *   put(key, contents, contentType, public=false) → URL (or signed URL for private)
 *   get(key)         → contents or null
 *   url(key, ttl=900) → signed URL good for `ttl` seconds
 *   delete(key)
 */
class StorageService
{
    public static function driver(): string
    {
        return ((string) Config::get('SPACES_BUCKET', '')) !== '' ? 'spaces' : 'local';
    }

    public static function put(string $key, string $contents, string $contentType = 'application/octet-stream', bool $public = false): string
    {
        if (self::driver() === 'spaces') {
            self::s3Put($key, $contents, $contentType, $public);
            return self::url($key);
        }
        $path = self::localPath($key);
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $contents);
        return '/storage/' . $key; // served by Apache via alias
    }

    public static function get(string $key): ?string
    {
        if (self::driver() === 'spaces') {
            return self::s3Get($key);
        }
        $path = self::localPath($key);
        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    public static function delete(string $key): void
    {
        if (self::driver() === 'spaces') {
            self::s3Delete($key);
            return;
        }
        $path = self::localPath($key);
        if (is_file($path)) @unlink($path);
    }

    public static function url(string $key, int $ttlSeconds = 900): string
    {
        if (self::driver() === 'spaces') {
            return self::s3SignedUrl($key, $ttlSeconds);
        }
        return '/storage/' . $key;
    }

    private static function localPath(string $key): string
    {
        $base = dirname(__DIR__, 2) . '/storage';
        $safe = preg_replace('#\.\.+#', '', $key);
        return $base . '/' . ltrim($safe, '/');
    }

    // ── S3 / Spaces signing (Signature V4) ──────────────────────────────────
    private static function s3Put(string $key, string $body, string $contentType, bool $public): void
    {
        [$url, $headers] = self::sign('PUT', $key, $body, [
            'Content-Type' => $contentType,
            'x-amz-acl' => $public ? 'public-read' : 'private',
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300) throw new \RuntimeException("Spaces PUT failed ($code): " . substr((string)$resp, 0, 500));
    }

    private static function s3Get(string $key): ?string
    {
        [$url, $headers] = self::sign('GET', $key);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 404) return null;
        if ($code >= 400) throw new \RuntimeException("Spaces GET failed ($code)");
        return (string) $resp;
    }

    private static function s3Delete(string $key): void
    {
        [$url, $headers] = self::sign('DELETE', $key);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 30,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Pre-signed URL (V4 query-string auth). Use for serving private uploads
     * to authenticated users without proxying through PHP.
     *
     * Addressing mode: virtual-hosted. Bucket goes in the hostname, NOT in
     * the canonical path. Earlier draft did `/$bucket$path` for the path,
     * which DigitalOcean and AWS both reject when the bucket is in the host.
     */
    private static function s3SignedUrl(string $key, int $ttl): string
    {
        [$bucket, $region, $accessKey, $secretKey, $host, $path] = self::s3Context($key);
        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $credential = "$accessKey/$date/$region/s3/aws4_request";
        $query = http_build_query([
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $credential,
            'X-Amz-Date' => $now,
            'X-Amz-Expires' => $ttl,
            'X-Amz-SignedHeaders' => 'host',
        ], '', '&', PHP_QUERY_RFC3986);
        $canon = "GET\n$path\n$query\nhost:$host\n\nhost\nUNSIGNED-PAYLOAD";
        $strToSign = "AWS4-HMAC-SHA256\n$now\n$date/$region/s3/aws4_request\n" . hash('sha256', $canon);
        $signKey = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', 's3',
                hash_hmac('sha256', $region,
                    hash_hmac('sha256', $date, 'AWS4' . $secretKey, true),
                true), true), true);
        $sig = hash_hmac('sha256', $strToSign, $signKey);
        return "https://$host$path?$query&X-Amz-Signature=$sig";
    }

    /** SigV4 signer for PUT/GET/DELETE. Returns [url, headers]. Virtual-hosted. */
    private static function sign(string $method, string $key, string $body = '', array $extraHeaders = []): array
    {
        [$bucket, $region, $accessKey, $secretKey, $host, $path] = self::s3Context($key);
        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $payloadHash = hash('sha256', $body);

        $headers = array_merge([
            'Host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $now,
        ], $extraHeaders);
        ksort($headers, SORT_FLAG_CASE | SORT_STRING);
        $signedHeaders = [];
        $canonHeaders = '';
        foreach ($headers as $k => $v) {
            $lk = strtolower($k);
            $signedHeaders[] = $lk;
            $canonHeaders .= $lk . ':' . trim((string) $v) . "\n";
        }
        $signedHeadersStr = implode(';', $signedHeaders);
        $canon = "$method\n$path\n\n$canonHeaders\n$signedHeadersStr\n$payloadHash";
        $strToSign = "AWS4-HMAC-SHA256\n$now\n$date/$region/s3/aws4_request\n" . hash('sha256', $canon);
        $signKey = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', 's3',
                hash_hmac('sha256', $region,
                    hash_hmac('sha256', $date, 'AWS4' . $secretKey, true),
                true), true), true);
        $sig = hash_hmac('sha256', $strToSign, $signKey);
        $auth = "AWS4-HMAC-SHA256 Credential=$accessKey/$date/$region/s3/aws4_request, SignedHeaders=$signedHeadersStr, Signature=$sig";
        $outHeaders = ['Authorization: ' . $auth];
        foreach ($headers as $k => $v) $outHeaders[] = "$k: $v";
        return ["https://$host$path", $outHeaders];
    }

    /**
     * Resolve shared S3 inputs and pick virtual-hosted addressing
     * (https://{bucket}.{region}.digitaloceanspaces.com{path}).
     * If SPACES_ENDPOINT already has the bucket in the host, honor it as-is.
     */
    private static function s3Context(string $key): array
    {
        $bucket = (string) Config::get('SPACES_BUCKET', '');
        $region = (string) Config::get('SPACES_REGION', 'nyc3');
        $endpoint = (string) Config::get('SPACES_ENDPOINT', "https://{$bucket}.{$region}.digitaloceanspaces.com");
        $accessKey = (string) Config::get('SPACES_KEY', '');
        $secretKey = (string) Config::get('SPACES_SECRET', '');
        $host = parse_url($endpoint, PHP_URL_HOST) ?: "{$bucket}.{$region}.digitaloceanspaces.com";
        // If the endpoint host does NOT include the bucket subdomain, prepend it.
        if (strpos($host, $bucket . '.') !== 0) {
            $host = $bucket . '.' . $host;
        }
        $path = '/' . ltrim($key, '/');
        return [$bucket, $region, $accessKey, $secretKey, $host, $path];
    }
}
