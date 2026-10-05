<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Guard;

use PHPUnit\Framework\TestCase;

/**
 * The conventions of 04_BACKEND.md section 0, held by a scan of the Truck Planner backend (tokens, comments
 * dropped): one rounding rule, one clock, one cache door, literal SQL in the repositories, no wording that
 * says a spot is lawful, and no response sent from below the controllers.
 *
 * If a check fails, change the code it names. Do not weaken the check.
 */
final class HouseRulesTest extends TestCase
{
    private const CLOCK = 'src/TruckPlanner/Services/Support/Clock.php';
    private const CACHE = 'src/TruckPlanner/Services/Support/TpCache.php';
    private const DATA = 'src/TruckPlanner/Data/';

    /** Files that may measure elapsed time. */
    private const MICROTIME_FILES = [
        'src/TruckPlanner/Services/Http/OutboundHttp.php',
        'src/TruckPlanner/Services/RoutingService.php',
    ];

    /** The one repository that may use the PDO handle: the region loader's blob and batch writes. */
    private const PDO_FILE = 'src/TruckPlanner/Data/RegionLoadRepository.php';

    private const WALL_CLOCK_FUNCTIONS = ['date', 'gmdate', 'time', 'strtotime', 'mktime', 'date_default_timezone_set'];

    /** Nothing Truck Planner says may state or imply that a spot is lawful to trade at. */
    private const LEGALITY_WORDS = ['legal', 'permitted', 'allowed to park', 'approved', 'permit'];

    /**
     * @return list<string>
     */
    private static function roots(): array
    {
        $roots = SourceScan::roots();
        self::assertGreaterThan(40, count($roots), 'the Truck Planner backend was not found');
        return $roots;
    }

    public function testRoundingIsTheModelsRuleOnly(): void
    {
        $found = [];
        foreach (self::roots() as $file) {
            foreach (SourceScan::calls($file) as [$name, $line]) {
                if ($name === 'round') {
                    $found[] = $file . ':' . $line;
                }
            }
        }
        self::assertSame([], $found, 'use Estimator::roundHalfAway');
    }

    public function testNothingReadsTheWallClockExceptThroughClock(): void
    {
        $found = [];
        foreach (self::roots() as $file) {
            foreach (SourceScan::calls($file) as [$name, $line]) {
                if (in_array($name, self::WALL_CLOCK_FUNCTIONS, true)) {
                    $found[] = $file . ':' . $line . ' ' . $name . '()';
                }
            }
            foreach (SourceScan::classUses($file) as [$class, $how, $line]) {
                if ($class === 'DateTime') {
                    $found[] = $file . ':' . $line . ' DateTime (' . $how . ')';
                }
                if ($class === 'DateTimeImmutable' && $file !== self::CLOCK) {
                    $found[] = $file . ':' . $line . ' DateTimeImmutable (' . $how . ')';
                }
            }
        }
        self::assertSame([], $found, 'ask Support\\Clock, with the time zone you mean');
    }

    public function testElapsedTimeIsMeasuredOnlyWhereACallIsTimed(): void
    {
        $found = [];
        foreach (self::roots() as $file) {
            if (in_array($file, self::MICROTIME_FILES, true)) {
                continue;
            }
            foreach (SourceScan::calls($file) as [$name, $line]) {
                if ($name === 'microtime') {
                    $found[] = $file . ':' . $line;
                }
            }
        }
        self::assertSame([], $found);
    }

    public function testTheHouseCacheIsReachedThroughTpCacheOnly(): void
    {
        $found = [];
        foreach (self::roots() as $file) {
            if ($file === self::CACHE) {
                continue;
            }
            foreach (SourceScan::identifiers($file) as [$name, $line]) {
                if ($name === 'CacheService') {
                    $found[] = $file . ':' . $line;
                }
            }
        }
        self::assertSame([], $found, 'use Support\\TpCache');
        self::assertContains('CacheService', array_column(SourceScan::identifiers(self::CACHE), 0));
    }

    public function testRepositoriesWriteLiteralSql(): void
    {
        $found = [];
        foreach (self::roots() as $file) {
            if (!str_starts_with($file, self::DATA)) {
                continue;
            }
            foreach (SourceScan::methodCalls($file) as [$name, $line]) {
                if (in_array($name, ['insert', 'update', 'delete'], true)) {
                    $found[] = $file . ':' . $line . ' ->' . $name . '()';
                }
                if ($name === 'pdo' && $file !== self::PDO_FILE) {
                    $found[] = $file . ':' . $line . ' ->pdo()';
                }
            }
            foreach (SourceScan::strings($file) as [$text, $line]) {
                if (preg_match('/\bSELECT\s+(\w+\.)?\*/i', $text) === 1) {
                    $found[] = $file . ':' . $line . ' SELECT *';
                }
            }
        }
        self::assertSame([], $found, 'repositories use query(), fetch() and fetchAll() with every column named');
    }

    public function testNoTextSaysOrImpliesThatASpotIsLawful(): void
    {
        $found = [];
        foreach (self::roots() as $file) {
            foreach (SourceScan::strings($file) as [$text, $line]) {
                foreach (self::LEGALITY_WORDS as $word) {
                    if (stripos($text, $word) !== false) {
                        $found[] = $file . ':' . $line . ' "' . $word . '"';
                    }
                }
            }
        }
        self::assertSame([], $found);
    }

    public function testOnlyControllersAnswerARequest(): void
    {
        $found = [];
        foreach (self::roots() as $file) {
            if (str_starts_with($file, 'src/Controllers/')) {
                continue;
            }
            foreach (SourceScan::classUses($file) as [$class, $how, $line]) {
                if ($class === 'Response') {
                    $found[] = $file . ':' . $line;
                }
            }
        }
        self::assertSame([], $found, 'Response::* ends the process: services throw, controllers answer');
    }

    public function testEveryLogLineCarriesTheTruckPlannerTag(): void
    {
        $found = [];
        foreach (self::roots() as $file) {
            $tokens = array_values(array_filter(SourceScan::tokens($file), static fn (array $t): bool => $t[0] !== T_WHITESPACE));
            $count = count($tokens);
            for ($i = 0; $i + 2 < $count; $i++) {
                if ($tokens[$i][0] !== T_STRING || strtolower($tokens[$i][1]) !== 'error_log' || $tokens[$i + 1][0] !== '(') {
                    continue;
                }
                if ($i > 0 && in_array($tokens[$i - 1][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                    continue;
                }
                $first = $tokens[$i + 2];
                $text = null;
                if ($first[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $text = substr($first[1], 1);
                } elseif ($first[0] === '"' && ($tokens[$i + 3][0] ?? null) === T_ENCAPSED_AND_WHITESPACE) {
                    $text = $tokens[$i + 3][1];
                }
                if ($text === null || !str_starts_with($text, '[tp] ')) {
                    $found[] = $file . ':' . $tokens[$i][2];
                }
            }
        }
        self::assertSame([], $found, 'a log line starts with the literal "[tp] "');
    }

    public function testEveryFileDeclaresStrictTypes(): void
    {
        $found = [];
        foreach (self::roots() as $file) {
            if (preg_match('/^<\?php\s+declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', SourceScan::code($file)) !== 1) {
                $found[] = $file;
            }
        }
        self::assertSame([], $found);
    }

    public function testTheChecksAboveCanFail(): void
    {
        // The scanner finds each kind of thing in a file that is known to hold it.
        $clockUses = array_map(static fn (array $use): array => [$use[0], $use[1]], SourceScan::classUses(self::CLOCK));
        self::assertContains(['DateTimeImmutable', 'new'], $clockUses);
        self::assertContains(['DateTimeImmutable', 'static'], $clockUses);
        self::assertContains('Response', array_column(SourceScan::classUses('src/Controllers/TruckBaseController.php'), 0));
        self::assertContains('query', array_column(SourceScan::methodCalls('src/TruckPlanner/Data/TruckRepository.php'), 0));
        $strings = array_column(SourceScan::strings('src/Controllers/TruckBaseController.php'), 0);
        self::assertContains('Set up your truck first', $strings);
        self::assertContains('[tp] ', $strings);
    }
}
