<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Guard;

use App\Controllers\TruckBaseController;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Router;
use App\Services\GooglePricing;
use PHPUnit\Framework\TestCase;

/**
 * The Truck block of config/routes.php is the route table of 04_BACKEND.md section 3: the same 44 routes
 * in the same order, each on its controller action, behind the middleware profile the table names.
 *
 * The block is checked twice: as source (what the file says) and as the table the real Router builds from
 * it (what a request meets). The router takes the first pattern that matches the path and the verb, so the
 * order of the lines is behaviour.
 */
final class RouteTableTest extends TestCase
{
    private const MARKER = '// Truck Planner. Not plan-gated. A rate-limit profile only where the request can reach a Google quota.';

    /** The six profiles, as written in the specification (spaces squeezed). */
    private const PROFILES = [
        '$tpAuth' => '[Middleware::auth()]',
        '$tpDrive' => "[Middleware::auth(), Middleware::rateLimit('tp_drive', 240, 3600)]",
        '$tpSuggest' => "[Middleware::auth(), Middleware::rateLimit('tp_suggest', 30, 3600)]",
        '$tpScout' => "[Middleware::auth(), Middleware::rateLimit('tp_scout', 60, 3600)]",
        '$tpContact' => "[Middleware::auth(), Middleware::rateLimit('tp_contact', 20, 3600)]",
        '$tpOwner' => "[Middleware::auth(), Middleware::requireRole(['owner', 'admin'])]",
    ];

    /** What each profile is at run time: the middleware after auth, as [kind, arguments]. */
    private const PROFILE_MIDDLEWARE = [
        '$tpAuth' => [],
        '$tpDrive' => [['rateLimit', ['tp_drive', 240, 3600]]],
        '$tpSuggest' => [['rateLimit', ['tp_suggest', 30, 3600]]],
        '$tpScout' => [['rateLimit', ['tp_scout', 60, 3600]]],
        '$tpContact' => [['rateLimit', ['tp_contact', 20, 3600]]],
        '$tpOwner' => [['requireRole', [['owner', 'admin']]]],
    ];

    /** Section 3, rows 1 to 44: [verb, path, controller, method, profile]. */
    private const ROUTES = [
        ['GET', '/api/truck/bootstrap', 'TruckBootstrapController', 'show', '$tpAuth'],
        ['GET', '/api/truck/profile', 'TruckProfileController', 'show', '$tpAuth'],
        ['PUT', '/api/truck/profile', 'TruckProfileController', 'upsert', '$tpAuth'],
        ['GET', '/api/truck/assumptions', 'TruckAssumptionsController', 'show', '$tpAuth'],
        ['PUT', '/api/truck/assumptions', 'TruckAssumptionsController', 'update', '$tpAuth'],
        ['POST', '/api/truck/assumptions/reset', 'TruckAssumptionsController', 'reset', '$tpAuth'],
        ['GET', '/api/truck/regions', 'TruckRegionController', 'index', '$tpAuth'],
        ['GET', '/api/truck/regions/{region_id}/pack/{dataset_version}', 'TruckRegionController', 'pack', '$tpAuth'],
        ['POST', '/api/truck/simulate', 'TruckSimulateController', 'simulate', '$tpAuth'],
        ['GET', '/api/truck/spots', 'TruckSpotController', 'index', '$tpAuth'],
        ['POST', '/api/truck/spots', 'TruckSpotController', 'create', '$tpAuth'],
        ['POST', '/api/truck/spots/refresh', 'TruckSpotController', 'refreshStale', '$tpAuth'],
        ['GET', '/api/truck/spots/{id}', 'TruckSpotController', 'show', '$tpAuth'],
        ['PUT', '/api/truck/spots/{id}', 'TruckSpotController', 'update', '$tpAuth'],
        ['DELETE', '/api/truck/spots/{id}', 'TruckSpotController', 'destroy', '$tpAuth'],
        ['POST', '/api/truck/spots/{id}/refresh', 'TruckSpotController', 'refresh', '$tpAuth'],
        ['GET', '/api/truck/day-context', 'TruckDayContextController', 'show', '$tpAuth'],
        ['POST', '/api/truck/drive-times', 'TruckDriveController', 'compute', '$tpDrive'],
        ['GET', '/api/truck/drive-times/overrides', 'TruckDriveController', 'overrides', '$tpAuth'],
        ['PUT', '/api/truck/drive-times/overrides', 'TruckDriveController', 'saveOverride', '$tpAuth'],
        ['DELETE', '/api/truck/drive-times/overrides/{id}', 'TruckDriveController', 'destroyOverride', '$tpAuth'],
        ['GET', '/api/truck/plans', 'TruckPlanController', 'index', '$tpAuth'],
        ['POST', '/api/truck/plans', 'TruckPlanController', 'create', '$tpDrive'],
        ['POST', '/api/truck/plans/evaluate', 'TruckPlanController', 'preview', '$tpDrive'],
        ['GET', '/api/truck/plans/{id}', 'TruckPlanController', 'show', '$tpAuth'],
        ['PUT', '/api/truck/plans/{id}', 'TruckPlanController', 'update', '$tpDrive'],
        ['DELETE', '/api/truck/plans/{id}', 'TruckPlanController', 'destroy', '$tpAuth'],
        ['POST', '/api/truck/plans/{id}/evaluate', 'TruckPlanController', 'evaluate', '$tpDrive'],
        ['POST', '/api/truck/suggest/day', 'TruckSuggestController', 'day', '$tpSuggest'],
        ['POST', '/api/truck/suggest/week', 'TruckSuggestController', 'week', '$tpSuggest'],
        ['GET', '/api/truck/services', 'TruckServiceLogController', 'index', '$tpAuth'],
        ['POST', '/api/truck/services', 'TruckServiceLogController', 'create', '$tpAuth'],
        ['GET', '/api/truck/services/{id}', 'TruckServiceLogController', 'show', '$tpAuth'],
        ['PUT', '/api/truck/services/{id}', 'TruckServiceLogController', 'update', '$tpAuth'],
        ['DELETE', '/api/truck/services/{id}', 'TruckServiceLogController', 'destroy', '$tpAuth'],
        ['GET', '/api/truck/calibration', 'TruckCalibrationController', 'show', '$tpAuth'],
        ['GET', '/api/truck/accuracy', 'TruckCalibrationController', 'accuracy', '$tpAuth'],
        ['GET', '/api/truck/scout', 'TruckScoutController', 'index', '$tpScout'],
        ['PUT', '/api/truck/scout/leads/{place_key}', 'TruckScoutController', 'saveLead', '$tpAuth'],
        ['POST', '/api/truck/scout/leads/{place_key}/contact', 'TruckScoutController', 'contact', '$tpContact'],
        ['POST', '/api/truck/scout/leads/{place_key}/spot', 'TruckScoutController', 'saveAsSpot', '$tpAuth'],
        ['GET', '/api/truck/export', 'TruckDataController', 'export', '$tpAuth'],
        ['POST', '/api/truck/data/delete', 'TruckDataController', 'destroy', '$tpOwner'],
        ['GET', '/api/truck/sources', 'TruckDataController', 'sources', '$tpAuth'],
    ];

    private static function file(): string
    {
        return SourceScan::root() . '/config/routes.php';
    }

    /**
     * The lines of the Truck block: from the marker comment to the end of the routing closure.
     *
     * @return list<string>
     */
    private static function blockLines(): array
    {
        $text = str_replace("\r\n", "\n", (string) file_get_contents(self::file()));
        $start = strpos($text, self::MARKER);
        self::assertNotFalse($start, 'the Truck Planner block is not in config/routes.php');
        self::assertSame(1, substr_count($text, self::MARKER), 'the block appears once');
        $end = strrpos($text, "\n};");
        self::assertNotFalse($end);
        self::assertGreaterThan($start, $end);
        self::assertSame("\n};\n", substr($text, $end), 'the block is the last thing in the routing closure');
        return explode("\n", substr($text, $start, $end - $start));
    }

    /**
     * The table the real Router builds from the real file.
     *
     * @return list<array{method: string, pattern: string, regex: string, handler: mixed, middleware: array<int, callable>}>
     */
    private static function table(): array
    {
        $router = new Router();
        $register = require self::file();
        $register($router);
        return (new \ReflectionProperty(Router::class, 'routes'))->getValue($router);
    }

    public function testTheBlockHoldsExactlyTheFortyFourRoutesInOrder(): void
    {
        self::assertCount(44, self::ROUTES);
        $found = [];
        foreach (self::blockLines() as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '//') || preg_match('/^\$tp[A-Za-z]+\s*=/', $line) === 1) {
                continue;
            }
            self::assertMatchesRegularExpression(
                '/^\$r->(get|post|put|delete)\(\'([^\']+)\',\s*\[(\w+)::class,\s*\'(\w+)\'\],\s*(\$tp\w+)\);$/',
                $line,
                'a line of the Truck block that is not a route, a profile or a comment'
            );
            preg_match('/^\$r->(\w+)\(\'([^\']+)\',\s*\[(\w+)::class,\s*\'(\w+)\'\],\s*(\$tp\w+)\);$/', $line, $m);
            $found[] = [strtoupper($m[1]), $m[2], $m[3], $m[4], $m[5]];
        }
        self::assertSame(self::ROUTES, $found);
    }

    public function testTheSixMiddlewareProfilesAreAsSpecified(): void
    {
        $found = [];
        foreach (self::blockLines() as $line) {
            if (preg_match('/^\s*(\$tp[A-Za-z]+)\s*=\s*(.+);\s*$/', $line, $m) === 1) {
                $found[$m[1]] = (string) preg_replace('/\s+/', ' ', $m[2]);
            }
        }
        self::assertSame(self::PROFILES, $found);
    }

    public function testEveryControllerIsImportedAndEveryActionExists(): void
    {
        $text = (string) file_get_contents(self::file());
        $seen = [];
        foreach (self::ROUTES as [$verb, $path, $controller, $method]) {
            $class = 'App\\Controllers\\' . $controller;
            if (!isset($seen[$controller])) {
                $seen[$controller] = true;
                self::assertSame(1, preg_match('/^use ' . preg_quote($class, '/') . ';\r?$/m', $text), $controller . ' is not imported in config/routes.php');
                self::assertTrue(class_exists($class), $class . ' does not exist');
                self::assertTrue(is_subclass_of($class, TruckBaseController::class), $controller . ' does not extend TruckBaseController');
                $constructor = (new \ReflectionClass($class))->getConstructor();
                self::assertTrue(
                    $constructor === null || $constructor->getNumberOfRequiredParameters() === 0,
                    $controller . ' cannot be built with no arguments, as the router does'
                );
            }
            self::assertTrue(method_exists($class, $method), $verb . ' ' . $path . ': ' . $controller . '::' . $method . ' does not exist');
            $action = new \ReflectionMethod($class, $method);
            self::assertTrue($action->isPublic() && !$action->isStatic(), $controller . '::' . $method . ' is not a public instance method');
            self::assertSame(1, $action->getNumberOfParameters(), $controller . '::' . $method . ' takes the request and nothing else');
            self::assertSame(Request::class, (string) $action->getParameters()[0]->getType());
            self::assertSame('void', (string) $action->getReturnType());
        }
        self::assertCount(14, $seen);
    }

    public function testPatternsAreUniquePlainPaths(): void
    {
        $keys = [];
        foreach (self::ROUTES as [$verb, $path]) {
            self::assertStringStartsWith('/api/truck/', $path);
            self::assertStringNotContainsString('.', $path, 'a dot in a pattern is a regular-expression wildcard');
            self::assertStringEndsNotWith('/', $path);
            self::assertMatchesRegularExpression('#^(/([a-z-]+|\{[a-z_]+\}))+$#', $path);
            $keys[] = $verb . ' ' . $path;
        }
        self::assertSame($keys, array_values(array_unique($keys)));
    }

    public function testTheRouterBuildsTheSameTableAtTheEndOfItsList(): void
    {
        $table = self::table();
        $truck = [];
        foreach ($table as $index => $route) {
            if (str_starts_with($route['pattern'], '/api/truck')) {
                $truck[$index] = $route;
            }
        }
        self::assertCount(44, $truck, 'no Truck Planner route is registered outside the block');
        self::assertSame(range(count($table) - 44, count($table) - 1), array_keys($truck), 'the block is contiguous and last');

        $i = 0;
        foreach ($truck as $route) {
            [$verb, $path, $controller, $method] = self::ROUTES[$i++];
            self::assertSame($verb, $route['method']);
            self::assertSame($path, $route['pattern']);
            self::assertSame(['App\\Controllers\\' . $controller, $method], $route['handler']);
        }
    }

    public function testEveryRouteIsReachedByItsOwnPath(): void
    {
        // What Router::dispatch does: the first route whose pattern matches the path and whose verb is the
        // request's. A literal path registered below a pattern that also matches it would never be reached.
        $table = self::table();
        foreach (self::ROUTES as [$verb, $path, $controller, $method]) {
            $concrete = (string) preg_replace('/\{[a-z_]+\}/', 'x1', $path);
            $winner = null;
            foreach ($table as $route) {
                if ($route['method'] === $verb && preg_match($route['regex'], $concrete) === 1) {
                    $winner = $route;
                    break;
                }
            }
            self::assertNotNull($winner, $verb . ' ' . $concrete . ' matches no route');
            self::assertSame(
                [$path, 'App\\Controllers\\' . $controller, $method],
                [$winner['pattern'], $winner['handler'][0], $winner['handler'][1]],
                $verb . ' ' . $concrete . ' is taken by another route'
            );
        }
    }

    public function testNoEarlierPatternSwallowsALaterLiteralPath(): void
    {
        $table = self::table();
        $literal = [];
        foreach (self::ROUTES as [$verb, $path]) {
            if (!str_contains($path, '{')) {
                $literal[] = [$verb, $path];
            }
        }
        self::assertGreaterThan(20, count($literal));
        foreach ($literal as [$verb, $path]) {
            foreach ($table as $route) {
                if ($route['method'] !== $verb || preg_match($route['regex'], $path) !== 1) {
                    continue;
                }
                self::assertSame($path, $route['pattern'], $verb . ' ' . $path . ' is matched first by ' . $route['pattern']);
                break;
            }
        }
        // the two cases the order exists for
        self::assertSame('refreshStale', self::winner($table, 'POST', '/api/truck/spots/refresh')[1]);
        self::assertSame('preview', self::winner($table, 'POST', '/api/truck/plans/evaluate')[1]);
        self::assertSame('refresh', self::winner($table, 'POST', '/api/truck/spots/abc/refresh')[1]);
        self::assertSame('evaluate', self::winner($table, 'POST', '/api/truck/plans/abc/evaluate')[1]);
        self::assertSame('overrides', self::winner($table, 'GET', '/api/truck/drive-times/overrides')[1]);
    }

    public function testEveryRouteRunsTheMiddlewareOfItsProfile(): void
    {
        $table = self::table();
        $offset = count($table) - 44;
        foreach (self::ROUTES as $i => [$verb, $path, $controller, $method, $profile]) {
            $middleware = $table[$offset + $i]['middleware'];
            self::assertNotEmpty($middleware, $verb . ' ' . $path . ' has no middleware');
            // auth always comes first: the rate limiter and the role gate read the user it loads
            self::assertSame(['auth', []], self::describe($middleware[0]), $verb . ' ' . $path);
            $rest = [];
            foreach (array_slice($middleware, 1) as $callable) {
                $rest[] = self::describe($callable);
            }
            self::assertSame(self::PROFILE_MIDDLEWARE[$profile], $rest, $verb . ' ' . $path);
        }
    }

    public function testRateLimitNamesCostNothingAndFitTheirColumn(): void
    {
        foreach (['tp_drive', 'tp_suggest', 'tp_scout', 'tp_contact'] as $name) {
            // not in the Google price book: the usage row costs 0 and no response carries a cost
            self::assertArrayNotHasKey($name, GooglePricing::COSTS);
            self::assertLessThanOrEqual(50, strlen($name), 'api_usage_log.api_name is VARCHAR(50)');
        }
    }

    public function testTheRoutesFileKeepsOneKindOfLineEnding(): void
    {
        $bytes = (string) file_get_contents(self::file());
        $crlf = substr_count($bytes, "\r\n");
        $lf = substr_count($bytes, "\n");
        self::assertSame(0, substr_count($bytes, "\r") - $crlf, 'a lone carriage return');
        self::assertTrue($crlf === $lf || $crlf === 0, 'config/routes.php mixes CRLF and LF line endings (' . $crlf . ' of ' . $lf . ' are CRLF)');
    }

    /**
     * @param list<array<string, mixed>> $table
     * @return array{0: string, 1: string} [controller class, method]
     */
    private static function winner(array $table, string $verb, string $path): array
    {
        foreach ($table as $route) {
            if ($route['method'] === $verb && preg_match($route['regex'], $path) === 1) {
                return $route['handler'];
            }
        }
        self::fail($verb . ' ' . $path . ' matches no route');
    }

    /**
     * Which Middleware factory made this closure, and with what arguments.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function describe(callable $middleware): array
    {
        self::assertInstanceOf(\Closure::class, $middleware);
        $closure = new \ReflectionFunction($middleware);
        self::assertSame((new \ReflectionClass(Middleware::class))->getFileName(), $closure->getFileName());
        foreach (['auth', 'rateLimit', 'requireRole'] as $factory) {
            $method = new \ReflectionMethod(Middleware::class, $factory);
            if ($closure->getStartLine() >= $method->getStartLine() && $closure->getEndLine() <= $method->getEndLine()) {
                $bound = $closure->getStaticVariables();
                if ($factory === 'rateLimit') {
                    return [$factory, [$bound['apiName'], $bound['maxRequests'], $bound['windowSeconds']]];
                }
                if ($factory === 'requireRole') {
                    return [$factory, [$bound['roles']]];
                }
                return [$factory, []];
            }
        }
        self::fail('a middleware that is neither auth, rateLimit nor requireRole');
    }
}
