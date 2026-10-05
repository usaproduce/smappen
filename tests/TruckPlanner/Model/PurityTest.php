<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The model is pure (02_MODEL.md 1.5, section 7): no clock, no time zone, no randomness, no locale, no
 * environment, no I/O, no runtime rounding. This test reads the tokens of every file in
 * src/TruckPlanner/Model (comments do not count) and fails on a call or a reference that would break that.
 */
final class PurityTest extends TestCase
{
    private const DIRECTORY = '/src/TruckPlanner/Model';

    /** Plain function calls that may not appear, lower case. */
    private const BANNED_CALLS = [
        // the clock and the process time zone
        'date', 'gmdate', 'idate', 'getdate', 'localtime', 'time', 'microtime', 'hrtime', 'mktime', 'gmmktime',
        'strtotime', 'strftime', 'gmstrftime', 'gettimeofday', 'date_create', 'date_create_immutable',
        'date_default_timezone_get', 'date_default_timezone_set', 'checkdate', 'cal_days_in_month', 'jdtogregorian',
        // randomness
        'rand', 'mt_rand', 'random_int', 'random_bytes', 'lcg_value', 'srand', 'mt_srand', 'getrandmax',
        'mt_getrandmax', 'uniqid', 'shuffle', 'str_shuffle', 'array_rand',
        // rounding, powers and sums the document forbids (1.4, section 7)
        'round', 'number_format', 'intdiv', 'pow', 'array_sum', 'array_product', 'fmod',
        // locale-dependent text
        'setlocale', 'localeconv', 'strtolower', 'strtoupper', 'ucfirst', 'ucwords', 'lcfirst', 'mb_strtolower',
        'mb_strtoupper', 'strcoll', 'strcasecmp', 'strncasecmp', 'natcasesort', 'money_format',
        // environment and configuration
        'getenv', 'putenv', 'ini_get', 'ini_set', 'error_log', 'sleep', 'usleep', 'gethostname', 'php_uname',
        // files, streams, processes, the network
        'file_get_contents', 'file_put_contents', 'fopen', 'file', 'readfile', 'fsockopen', 'pfsockopen',
        'stream_socket_client', 'stream_context_create', 'socket_create', 'get_headers', 'exec', 'shell_exec',
        'system', 'passthru', 'proc_open', 'popen', 'mail', 'header', 'setcookie', 'session_start',
    ];

    private const BANNED_CALL_PREFIXES = ['curl_'];

    /** Class, interface or namespace segments that may not be referenced. */
    private const BANNED_NAMES = [
        'Database', 'Config', 'Response', 'Request', 'CacheService', 'Logger',
        'DateTime', 'DateTimeImmutable', 'DateTimeZone', 'DateTimeInterface', 'DateInterval', 'DatePeriod',
        'Randomizer', 'PDO', 'NumberFormatter', 'IntlDateFormatter', 'Collator',
    ];

    private const SUPERGLOBALS = ['$_SERVER', '$_ENV', '$_GET', '$_POST', '$_COOKIE', '$_REQUEST', '$_FILES', '$_SESSION', '$GLOBALS'];

    public function testModelFilesArePure(): void
    {
        $files = self::modelFiles();
        self::assertGreaterThanOrEqual(20, count($files));
        self::assertContains('Estimator.php', array_map('basename', $files));
        self::assertContains('SeedsData.php', array_map('basename', $files));

        $findings = [];
        foreach ($files as $file) {
            $code = file_get_contents($file);
            self::assertIsString($code);
            foreach (self::scan($code) as $finding) {
                $findings[] = basename($file) . ':' . $finding;
            }
        }
        self::assertSame([], $findings, "the model must stay pure:\n  " . implode("\n  ", $findings));
    }

    /** The seed file is read with one `require` in Seeds.php; nothing else is loaded at run time. */
    public function testOnlySeedsLoadsAFile(): void
    {
        $loaders = [];
        foreach (self::modelFiles() as $file) {
            foreach (\PhpToken::tokenize((string) file_get_contents($file)) as $token) {
                if ($token->is([T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE, T_EVAL])) {
                    $loaders[] = basename($file) . ' ' . $token->getTokenName();
                }
            }
        }
        self::assertSame(['Seeds.php T_REQUIRE'], $loaders);
    }

    public function testEveryFileIsStrictAndInTheModelNamespace(): void
    {
        foreach (self::modelFiles() as $file) {
            $code = (string) file_get_contents($file);
            self::assertMatchesRegularExpression('/^<\?php\s+declare\(strict_types=1\);/', $code, basename($file));
            if (basename($file) !== 'SeedsData.php') {
                self::assertMatchesRegularExpression('/\nnamespace App\\\\TruckPlanner\\\\Model;\r?\n/', $code, basename($file));
            }
        }
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function snippets(): iterable
    {
        // code => must the scanner object?
        yield 'date call' => ['<?php $x = date("Y");', true];
        yield 'fully qualified call' => ['<?php $x = \round(1.5);', true];
        yield 'call with a space' => ['<?php $x = time ();', true];
        yield 'upper case call' => ['<?php $x = MT_RAND();', true];
        yield 'curl' => ['<?php $h = curl_init();', true];
        yield 'file_get_contents with a URL' => ['<?php $x = file_get_contents("https://example.org/x");', true];
        yield 'a URL literal alone' => ['<?php $x = "https://example.org/x";', true];
        yield 'shuffle' => ['<?php shuffle($a);', true];
        yield 'setlocale' => ['<?php setlocale(LC_ALL, "C");', true];
        yield 'strtotime' => ['<?php $t = strtotime("now");', true];
        yield 'mktime' => ['<?php $t = mktime(0, 0, 0, 1, 1, 2026);', true];
        yield 'random_int' => ['<?php $r = random_int(1, 6);', true];
        yield 'array_rand' => ['<?php $k = array_rand($a);', true];
        yield 'rand' => ['<?php $r = rand();', true];
        yield 'first-class callable' => ['<?php $f = round(...);', true];
        yield 'Database reference' => ['<?php $db = \App\Core\Database::getInstance();', true];
        yield 'Config import' => ['<?php use App\Core\Config; ', true];
        yield 'Response call' => ['<?php Response::success([]);', true];
        yield 'new DateTime' => ['<?php $d = new DateTime();', true];
        yield 'DateTimeImmutable type' => ['<?php function f(\DateTimeImmutable $d): void {}', true];
        yield 'power operator' => ['<?php $y = $x ** 2;', true];
        yield 'runtime pi' => ['<?php $y = M_PI * 2;', true];
        yield 'superglobal' => ['<?php $y = $_SERVER["TZ"];', true];
        yield 'backticks' => ['<?php $y = `date`;', true];

        yield 'a method called date' => ['<?php $x = $o->date("Y");', false];
        yield 'a nullsafe method called time' => ['<?php $x = $o?->time();', false];
        yield 'a static method called round' => ['<?php $x = Num::round(1.5);', false];
        yield 'a method declared as round' => ['<?php class A { public static function round(float $x): float { return $x; } }', false];
        yield 'names that only contain a banned word' => ['<?php $x = roundHalfAway(1.5, 0) + dateOfDay(3) + parseDate("x");', false];
        yield 'array keys and strings' => ['<?php $x = ["date" => $d, "time" => 1, "round" => 2]; $y = $x["date"];', false];
        yield 'a comment' => ["<?php // date('Y'), Database, Config, Response, https://example.org\n/* round(1.5) */ \$x = 1;", false];
        yield 'a property' => ['<?php $x = $o->Config; $y = $o->date;', false];
        yield 'floor and abs' => ['<?php $x = floor(abs(-1.5) + 0.5) + sqrt(2.0) + exp(1.0) + log(2.0);', false];
    }

    #[DataProvider('snippets')]
    public function testScannerRecognises(string $code, bool $mustObject): void
    {
        $findings = self::scan($code);
        if ($mustObject) {
            self::assertNotSame([], $findings, 'the scanner must object to: ' . $code);
        } else {
            self::assertSame([], $findings, 'the scanner must accept: ' . $code);
        }
    }

    // ---------------------------------------------------------------------------------------------------

    /** @return list<string> */
    private static function modelFiles(): array
    {
        $files = glob(dirname(__DIR__, 3) . self::DIRECTORY . '/*.php');
        self::assertIsArray($files);
        sort($files);
        return $files;
    }

    /**
     * @return list<string> findings as "<line>: <what>"
     */
    private static function scan(string $code): array
    {
        $tokens = [];
        foreach (\PhpToken::tokenize($code) as $token) {
            if (!$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                $tokens[] = $token;
            }
        }
        $findings = [];
        foreach ($tokens as $i => $token) {
            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
                $segments = explode('\\', $token->text);
                $base = (string) end($segments);
                $isMember = $previous !== null && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON]);
                foreach ($segments as $segment) {
                    if (!$isMember && in_array($segment, self::BANNED_NAMES, true)) {
                        $findings[] = $token->line . ': reference to ' . $token->text;
                    }
                }
                $isCall = $next !== null && $next->text === '(' && !$isMember
                    && !($previous !== null && $previous->is([T_FUNCTION, T_NEW]));
                if ($isCall) {
                    $name = strtolower($base);
                    $banned = in_array($name, self::BANNED_CALLS, true);
                    foreach (self::BANNED_CALL_PREFIXES as $prefix) {
                        $banned = $banned || str_starts_with($name, $prefix);
                    }
                    if ($banned) {
                        $findings[] = $token->line . ': call to ' . $token->text . '()';
                    }
                }
                if (!$isMember && preg_match('/^M_[A-Z0-9_]+$/', $base) === 1) {
                    $findings[] = $token->line . ': runtime math constant ' . $base;
                }
            } elseif ($token->is([T_POW, T_POW_EQUAL])) {
                $findings[] = $token->line . ': the power operator';
            } elseif ($token->is(T_VARIABLE) && in_array($token->text, self::SUPERGLOBALS, true)) {
                $findings[] = $token->line . ': superglobal ' . $token->text;
            } elseif ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) && str_contains($token->text, '://')) {
                $findings[] = $token->line . ': a URL literal';
            } elseif ($token->text === '`') {
                $findings[] = $token->line . ': a shell command';
            }
        }
        return $findings;
    }
}
