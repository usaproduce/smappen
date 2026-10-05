<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Support;

/**
 * Catches what the code under test writes with error_log(), so a test can read it (and so it does not
 * land in the test output).
 *
 *     $lines = LogCapture::during(function () use ($client): void { $client->weekly(['R1Z'], '2026-09-07'); });
 *     self::assertSame(['[tp] eia http_500'], $lines);
 *     self::assertStringNotContainsString('api_key', implode("\n", $lines));
 */
final class LogCapture
{
    /**
     * @return list<string> the logged messages in order, without PHP's time stamp
     */
    public static function during(callable $fn): array
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'tplog');
        $previous = ini_set('error_log', $file);
        try {
            $fn();
        } finally {
            ini_set('error_log', is_string($previous) ? $previous : '');
            $raw = is_file($file) ? (string) file_get_contents($file) : '';
            @unlink($file);
        }
        $lines = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $lines[] = (string) preg_replace('/^\[[^\]]*\] /', '', $line);
        }
        return $lines;
    }
}
