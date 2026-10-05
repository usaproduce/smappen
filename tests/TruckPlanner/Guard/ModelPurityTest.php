<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Guard;

use PHPUnit\Framework\TestCase;

/**
 * The model is pure (02_MODEL.md 1.5, product rule "no AI at runtime" and its companions): the same
 * arguments give the same result on every machine. So src/TruckPlanner/Model may not reach a database, a
 * configuration, a cache, the network, a clock, a time zone, a locale or a random source, and it rounds,
 * sums and raises to powers only in the ways the specification writes out.
 *
 * The scan works on tokens with comments dropped. If it fails, change the model code. Do not weaken it.
 */
final class ModelPurityTest extends TestCase
{
    private const MODEL = 'src/TruckPlanner/Model';

    private const BANNED_IDENTIFIERS = [
        'Database', 'Config', 'CacheService', 'DateTime', 'DateTimeImmutable', 'DateTimeZone', 'M_PI',
    ];

    private const SUPERGLOBALS = [
        '$_SERVER', '$_ENV', '$_GET', '$_POST', '$_COOKIE', '$_FILES', '$_REQUEST', '$_SESSION', '$GLOBALS',
    ];

    private const BANNED_FUNCTIONS = [
        // clocks, calendars, time zones
        'date', 'gmdate', 'time', 'microtime', 'hrtime', 'strtotime', 'mktime', 'gmmktime', 'getdate', 'localtime',
        'idate', 'date_create', 'date_create_immutable', 'gettimeofday', 'strftime', 'gmstrftime',
        'date_default_timezone_get', 'date_default_timezone_set', 'usleep', 'sleep',
        // randomness
        'random_int', 'random_bytes', 'mt_rand', 'rand', 'shuffle', 'array_rand', 'str_shuffle', 'lcg_value',
        'uniqid', 'mt_srand', 'srand', 'mt_getrandmax',
        // rounding, powers and sums the specification replaces with its own
        'round', 'intdiv', 'pow', 'array_sum', 'number_format',
        // environment, locale, settings, files, logs
        'getenv', 'setlocale', 'localeconv', 'ini_get', 'ini_set', 'file_get_contents', 'fopen', 'error_log',
        // locale-dependent case mapping
        'strtolower', 'strtoupper', 'ucfirst', 'ucwords', 'lcfirst', 'mb_strtolower', 'mb_strtoupper',
    ];

    /**
     * @return list<string>
     */
    private static function files(): array
    {
        $files = SourceScan::phpFilesUnder(self::MODEL);
        self::assertNotEmpty($files, 'the model package is not there');
        return $files;
    }

    public function testTheModelNamesNoDatabaseConfigurationCacheOrDateClass(): void
    {
        $found = [];
        foreach (self::files() as $file) {
            foreach (SourceScan::identifiers($file) as [$name, $line]) {
                if (in_array($name, self::BANNED_IDENTIFIERS, true)) {
                    $found[] = $file . ':' . $line . ' ' . $name;
                }
            }
        }
        self::assertSame([], $found);
    }

    public function testTheModelReadsNoSuperglobal(): void
    {
        $found = [];
        foreach (self::files() as $file) {
            foreach (SourceScan::identifiers($file) as [$name, $line]) {
                if (in_array($name, self::SUPERGLOBALS, true)) {
                    $found[] = $file . ':' . $line . ' ' . $name;
                }
            }
        }
        self::assertSame([], $found);
    }

    public function testTheModelCallsNoClockRandomLocaleOrIoFunction(): void
    {
        $found = [];
        foreach (self::files() as $file) {
            foreach (SourceScan::calls($file) as [$name, $line]) {
                if (in_array($name, self::BANNED_FUNCTIONS, true) || str_starts_with($name, 'curl_')) {
                    $found[] = $file . ':' . $line . ' ' . $name . '()';
                }
            }
        }
        self::assertSame([], $found);
    }

    public function testTheModelHasNoPowerOperator(): void
    {
        $found = [];
        foreach (self::files() as $file) {
            if (SourceScan::hasToken($file, T_POW) || SourceScan::hasToken($file, T_POW_EQUAL)) {
                $found[] = $file;
            }
        }
        self::assertSame([], $found, 'powers are written as repeated multiplication or exp()');
    }

    public function testTheScannerSeesWhatItShould(): void
    {
        // A scan that finds nothing proves nothing unless the scanner is known to find things.
        $calls = array_column(SourceScan::calls('src/TruckPlanner/Services/Support/Clock.php'), 0);
        self::assertContains('in_array', $calls);
        self::assertNotContains('nowutc', $calls, 'a method call is not a plain call');
        self::assertNotContains('createfromformat', $calls, 'a static call is not a plain call');
        self::assertNotContains('epoch', $calls, 'a declaration is not a call');
        self::assertContains('DateTimeImmutable', array_column(SourceScan::identifiers('src/TruckPlanner/Services/Support/Clock.php'), 0));
        self::assertContains('ini_set', array_column(SourceScan::calls('src/TruckPlanner/Services/Support/JsonSafe.php'), 0));
        self::assertContains('microtime', array_column(SourceScan::calls('src/TruckPlanner/Services/Http/OutboundHttp.php'), 0));
        self::assertTrue(SourceScan::hasToken('tests/TruckPlanner/Services/TpConfigTest.php', T_POW));
        // comments are not code
        self::assertStringNotContainsString('The only reader of the wall clock', SourceScan::code('src/TruckPlanner/Services/Support/Clock.php'));
    }
}
