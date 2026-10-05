<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Support;

/**
 * Keeps secrets out of log lines. Every text that reaches error_log('[tp] ...') goes through text(); a URL
 * is only ever logged through url(). Upstream bodies are not logged at all.
 */
final class Redactor
{
    private const MAX_CHARS = 200;
    private const MASK = '[redacted]';

    /** Scheme, host and path of a URL. The query string, the fragment, the port and any credentials go. */
    public static function url(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return '[url]';
        }
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        return self::text($scheme . $parts['host'] . ($parts['path'] ?? ''));
    }

    /**
     * A message made safe for a log line: the values of `key=`, `api_key=` and `token=` parameters and any
     * Google key ("AIza...") are masked, line breaks and control characters become spaces, and the result
     * is cut to 200 characters.
     */
    public static function text(string $s): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_scrub($s, 'UTF-8');
        }
        $s = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s);
        $s = (string) preg_replace('/((?:api_key|key|token)=)[^&\s"\'<>]*/i', '$1' . self::MASK, $s);
        $s = (string) preg_replace('/AIza[0-9A-Za-z_\-]{6,}/', self::MASK, $s);
        if (mb_strlen($s, 'UTF-8') > self::MAX_CHARS) {
            $s = mb_substr($s, 0, self::MAX_CHARS, 'UTF-8');
        }
        return $s;
    }
}
