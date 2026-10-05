<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Guard;

use App\TruckPlanner\Services\Http\OutboundHttp;
use PHPUnit\Framework\TestCase;

/**
 * Product rule "no AI at runtime", and its neighbour "Google Maps only", as a scan of the Truck Planner
 * backend (04_BACKEND.md 8.1, DECISIONS sections 0 and 5).
 *
 * Roots: src/TruckPlanner, src/Controllers/Truck*Controller.php, scripts/truck, config/truck_planner.php.
 * PHP comments are dropped with the tokenizer and paths are written with forward slashes.
 *
 *   1. No root file names an LLM or ML host, key, wire format, model, SDK or library, nor one of the
 *      first-party classes and endpoints that reach an LLM today, nor a part of the platform that Truck
 *      Planner must stay clear of (another routing provider, the vendor and private-data namespaces).
 *   2. Nothing the roots can reach names an LLM host or key. "Reach" is every word in the code that is the
 *      name of a class file under src/, followed from file to file. That is an over-approximation on
 *      purpose: if a harmless word drags in a tainted file, reword the Truck Planner code.
 *   3. The backend opens no connection and no file except where it is allowed to, and every URL in it
 *      names one of the five hosts of DECISIONS section 0 or a host that is only ever linked to.
 *
 * If a check fails, change the Truck Planner code. Do not weaken the check.
 */
final class NoAiAtRuntimeTest extends TestCase
{
    // Lists A to F. Plain entries are case-insensitive substrings. Entries between tildes are regular expressions
    // (the specification writes them between slashes; here a plain entry may start with a slash).

    /** A: hosts and ports of LLM and ML services. */
    private const HOSTS = [
        'api.anthropic.com', 'api.openai.com', 'openai.azure.com', 'generativelanguage.googleapis.com',
        'aiplatform.googleapis.com', 'api.mistral.ai', 'api.cohere.ai', 'api.cohere.com', 'api.groq.com',
        'api.together.xyz', 'api.together.ai', 'openrouter.ai', 'api.perplexity.ai', 'api.x.ai', 'api.deepseek.com',
        'api.fireworks.ai', 'api.replicate.com', 'huggingface.co', 'api.voyageai.com', 'api.ai21.com',
        ':11434', ':8088', 'ml-sidecar',
        '~bedrock(-runtime)?\.[a-z0-9-]+\.amazonaws\.com~',
        '~(runtime\.)?sagemaker\.[a-z0-9-]+\.amazonaws\.com~',
    ];

    /** B: names of their keys and settings. */
    private const KEYS = [
        '~\b(ANTHROPIC|OPENAI|AZURE_OPENAI|GEMINI|GOOGLE_AI|GOOGLE_GENAI|MISTRAL|COHERE|GROQ|TOGETHER|OPENROUTER|PERPLEXITY|XAI|DEEPSEEK|FIREWORKS|REPLICATE|HUGGINGFACE|HF|VOYAGE|AI21)_(API_)?(KEY|TOKEN)\b~',
        'ML_SIDECAR_URL', 'OLLAMA_HOST',
    ];

    /** C: wire formats and model names. */
    private const WIRE = [
        'anthropic-version', 'anthropic-beta', '/v1/messages', '/v1/chat/completions', '/v1/completions',
        '/v1/embeddings', '/v1/responses', ':generateContent', ':streamGenerateContent', ':embedContent',
        '~\bclaude[-_ ]~i', '~\bgpt-?[0-9]~i', '~\bo[134]-(mini|preview|pro)\b~i', '~\bgemini-~i',
        '~\btext-embedding-~i', '~\b(haiku|sonnet|opus)\b~i', '~\bllama[-0-9]~i',
        '~\bmistral-(tiny|small|medium|large)~i', '~\bcommand-r~i',
    ];

    /** D: first-party code that reaches an LLM today. Class and method names match on word boundaries. */
    private const SYMBOLS = [
        '~\bAiScoringController\b~', '~\bMenuEngineeringService\b~', '~\bMenuEngineeringController\b~',
        '~\bSampleDataService\b~', '~\bOpsController\b~', '~\bnarrateWithClaude\b~', '~\bscoreWithClaude\b~',
        '~\bbriefingViaClaude\b~', '~\bdashboardBriefing\b~', '~\bhasAnthropicKey\b~', '~\bhaveAnthropicKey\b~',
        "'pos.sync'", '"pos.sync"', 'ai_score:', 'dash_briefing:',
        '~\b(FROM|JOIN|INTO|UPDATE)\s+`?recommendations`?\b~i',
    ];

    /** E: the endpoints of that code. */
    private const ENDPOINTS = [
        '/ai-score', '/ai-rankings', '/dashboard/briefing', '/recommendations/run', '/restaurants/sample',
        '~/menu-items/[^\'"`]+/recommend~', '~/pos/[^\'"`]+/sync~',
    ];

    /** F: LLM and ML libraries, as namespaces and as Composer packages. */
    private const LIBRARIES = [
        'Anthropic\\', 'OpenAI\\', 'Gemini\\', 'LLPhant\\', 'Prism\\', 'Phpml\\', 'Rubix\\ML\\',
        'openai-php/client', 'anthropic-ai/sdk', 'mozex/anthropic-php', 'google-gemini-php/client',
        'theodo-group/llphant', 'prism-php/prism', 'php-ml/php-ml', 'rubix/ml',
    ];

    /**
     * The house list (04_BACKEND.md section 6, rule 14): parts of the platform Truck Planner stays clear
     * of, the settings of any routing provider other than Google, and request-time map data services.
     */
    private const HOUSE = [
        'App\\PrivateData', 'App\\MarketData',
        '~\bPermitsService\b~', '~\bFootTrafficService\b~', '~\bTrafficService\b~', '~\bDriveTimeMatrixService\b~',
        '~\bIsochroneService\b~', '~\bOSMAdapter\b~', '~\bGoogleMapsService\b~', '~\bPlacesClient\b~',
        'ORS_API_KEY', 'ORS_BASE_URL', 'openrouteservice', 'overpass',
    ];

    /** Functions that open a connection, a file or a process. */
    private const OUTBOUND_FUNCTIONS = [
        'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_context_create', 'socket_create',
        'file_get_contents', 'fopen', 'file', 'readfile', 'copy', 'get_headers', 'simplexml_load_file',
        'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'mail',
    ];

    /** File => the functions it may call although they are on the list above ("curl_*" = every cURL function). */
    private const ALLOWED_CALLS = [
        'src/TruckPlanner/Services/Http/OutboundHttp.php' => ['curl_*'],
        'scripts/truck/smoke/SmokeClient.php' => ['curl_*'],
        'src/TruckPlanner/Services/RegionLoader.php' => ['fopen', 'file_get_contents', 'file'],
        'scripts/truck/load-region.php' => ['fopen', 'file_get_contents', 'file'],
        'scripts/truck/_bootstrap.php' => ['fopen', 'file_get_contents', 'file'],
    ];

    /** The hosts of DECISIONS section 0: the only ones the backend may call. */
    private const CALLED_HOSTS = [
        'routes.googleapis.com', 'maps.googleapis.com', 'places.googleapis.com', 'api.weather.gov', 'api.eia.gov',
    ];

    /** Hosts that appear only in links shown to the owner. Nothing is requested from them. */
    private const LINKED_HOSTS = ['www.google.com', 'www.openstreetmap.org', 'lehd.ces.census.gov'];

    private const LOCAL_HOSTS = ['127.0.0.1', 'localhost'];
    private const SMOKE_SCRIPT = 'scripts/truck/smoke.php';
    private const SMOKE_DIR = 'scripts/truck/smoke/';
    private const SMOKE_CLIENT = 'scripts/truck/smoke/SmokeClient.php';

    /**
     * @return list<string>
     */
    private static function roots(): array
    {
        $roots = SourceScan::roots();
        self::assertGreaterThan(40, count($roots), 'the Truck Planner backend was not found');
        return $roots;
    }

    /**
     * The first pattern of the lists that the text matches, or null.
     *
     * @param list<list<string>> $lists
     */
    private static function firstMatch(string $text, array $lists): ?string
    {
        foreach ($lists as $list) {
            foreach ($list as $pattern) {
                if ($pattern[0] === '~') {
                    if (preg_match($pattern, $text, $m) === 1) {
                        return $pattern . ' ("' . $m[0] . '")';
                    }
                } elseif (stripos($text, $pattern) !== false) {
                    return $pattern;
                }
            }
        }
        return null;
    }

    // ------------------------------------------------------------------------------------------ check 1

    public function testNoTruckPlannerFileNamesAnLlmOrAForbiddenPartOfThePlatform(): void
    {
        $lists = [self::HOSTS, self::KEYS, self::WIRE, self::SYMBOLS, self::ENDPOINTS, self::LIBRARIES, self::HOUSE];
        $found = [];
        foreach (self::roots() as $file) {
            $hit = self::firstMatch(SourceScan::code($file), $lists);
            if ($hit !== null) {
                $found[] = $file . ': ' . $hit;
            }
        }
        self::assertSame([], $found);
    }

    public function testComposerRequiresNoLlmOrMlLibrary(): void
    {
        $composer = (string) file_get_contents(SourceScan::root() . '/composer.json');
        self::assertNotSame('', $composer);
        self::assertNull(self::firstMatch($composer, [self::LIBRARIES]));
        self::assertNull(self::firstMatch(str_replace('\\\\', '\\', $composer), [self::LIBRARIES]));
    }

    public function testTheBareWordModelAndLookalikeNamesAreNotBanned(): void
    {
        $lists = [self::HOSTS, self::KEYS, self::WIRE, self::SYMBOLS, self::ENDPOINTS, self::LIBRARIES, self::HOUSE];
        foreach ([
            "'model_version' => 'tps-0.1.0'",
            'class TruckSampleDataService {}',
            'new PlacesContactClient()',
            '$copilot = "octopus"; $group = "o1-profile";',
            "Config::get('GOOGLE_API_KEY'); Config::get('EIA_API_KEY');",
            "'https://places.googleapis.com/v1/places:searchText'",
            "'INSERT INTO tp_scout_leads'",
        ] as $harmless) {
            self::assertNull(self::firstMatch($harmless, $lists), $harmless);
        }
        // and each list bites
        foreach ([
            "curl_init('https://api.anthropic.com/v1/messages')",
            "Config::get('OPENAI_API_KEY')",
            "'model' => 'claude-3-haiku'",
            'new SampleDataService()',
            "'/api/areas/' . \$id . '/ai-score'",
            'use OpenAI\\Client;',
            'new \\App\\Services\\TrafficService()',
            "Config::get('ORS_API_KEY')",
            "'https://overpass-api.de/api/interpreter'",
            'SELECT narrative FROM recommendations WHERE id = ?',
        ] as $tainted) {
            self::assertNotNull(self::firstMatch($tainted, $lists), $tainted);
        }
    }

    // ------------------------------------------------------------------------------------------ check 2

    public function testNothingTruckPlannerCanReachNamesAnLlmHostOrKey(): void
    {
        // class base name => files under src/ (relative)
        $byName = [];
        foreach (SourceScan::phpFilesUnder('src') as $file) {
            $byName[basename($file, '.php')][] = $file;
        }

        $cameFrom = [];
        $queue = [];
        foreach (self::roots() as $file) {
            $cameFrom[$file] = null;
            $queue[] = $file;
        }
        while ($queue !== []) {
            $file = array_shift($queue);
            preg_match_all('/\b[A-Za-z_][A-Za-z0-9_]*\b/', SourceScan::code($file), $m);
            foreach (array_unique($m[0]) as $word) {
                foreach ($byName[$word] ?? [] as $target) {
                    if (!array_key_exists($target, $cameFrom)) {
                        $cameFrom[$target] = $file;
                        $queue[] = $target;
                    }
                }
            }
        }

        $found = [];
        foreach (array_keys($cameFrom) as $file) {
            $hit = self::firstMatch(SourceScan::code($file), [self::HOSTS, self::KEYS]);
            if ($hit === null) {
                continue;
            }
            $chain = [];
            for ($step = $file; $step !== null; $step = $cameFrom[$step]) {
                $chain[] = $step;
            }
            $found[] = $hit . ' reached through ' . implode(' <- ', $chain);
        }
        self::assertSame([], $found);

        // The walk really leaves the roots: the base controller answers through the house Response class.
        self::assertArrayHasKey('src/Core/Response.php', $cameFrom);
        self::assertArrayHasKey('src/Core/Database.php', $cameFrom);
        // And the files it must never reach are tainted indeed, so the assertion above can fail.
        self::assertNotNull(self::firstMatch(SourceScan::code('src/Controllers/AiScoringController.php'), [self::HOSTS, self::KEYS]));
        self::assertArrayNotHasKey('src/Controllers/AiScoringController.php', $cameFrom);
    }

    // ------------------------------------------------------------------------------------------ check 3

    public function testConnectionsFilesAndProcessesAreOpenedOnlyWhereAllowed(): void
    {
        $found = [];
        foreach (self::roots() as $file) {
            $allowed = self::ALLOWED_CALLS[$file] ?? [];
            foreach (SourceScan::calls($file) as [$name, $line]) {
                $isCurl = str_starts_with($name, 'curl_');
                if (!$isCurl && !in_array($name, self::OUTBOUND_FUNCTIONS, true)) {
                    continue;
                }
                if (in_array($isCurl ? 'curl_*' : $name, $allowed, true)) {
                    continue;
                }
                $found[] = $file . ':' . $line . ' ' . $name . '()';
            }
            foreach (SourceScan::tokens($file) as $token) {
                if ($token[0] === '`') {
                    $found[] = $file . ':' . $token[2] . ' shell backticks';
                }
            }
        }
        self::assertSame([], $found, 'upstream calls go through Http\\OutboundHttp; request bodies come from Request::getRawBody()');
    }

    public function testTheOutboundAllowListIsTheFiveHostsOfTheDecisions(): void
    {
        self::assertSame(self::CALLED_HOSTS, OutboundHttp::ALLOWED_HOSTS);
    }

    public function testEveryUrlNamesAnAllowedHost(): void
    {
        $found = [];
        foreach (self::roots() as $file) {
            $local = $file === self::SMOKE_SCRIPT || str_starts_with($file, self::SMOKE_DIR);
            foreach (SourceScan::strings($file) as [$text, $line]) {
                preg_match_all('#https?://([^/\s\'"?\#<>]*)#i', $text, $m);
                foreach ($m[1] as $authority) {
                    $host = strtolower((string) preg_replace('/:\d*$/', '', $authority));
                    $ok = in_array($host, self::CALLED_HOSTS, true) || in_array($host, self::LINKED_HOSTS, true)
                        || ($local && in_array($host, self::LOCAL_HOSTS, true));
                    if (!$ok) {
                        $found[] = $file . ':' . $line . ' URL host "' . $authority . '"';
                    }
                }
                foreach (self::LOCAL_HOSTS as $name) {
                    if (!$local && preg_match('/(?<![A-Za-z0-9.])' . preg_quote($name, '/') . '(?![A-Za-z0-9])/i', $text) === 1) {
                        $found[] = $file . ':' . $line . ' ' . $name . ' outside the smoke test';
                    }
                }
            }
        }
        self::assertSame([], $found);
    }

    public function testTheSmokeClientKnowsNoHostButTheLocalOne(): void
    {
        if (!is_file(SourceScan::root() . '/' . self::SMOKE_CLIENT)) {
            self::markTestSkipped('the smoke client is not there yet');
        }
        $found = [];
        $sawLocal = false;
        foreach (SourceScan::strings(self::SMOKE_CLIENT) as [$text, $line]) {
            if (in_array($text, self::LOCAL_HOSTS, true)) {
                $sawLocal = true;
            }
            preg_match_all('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', $text, $ips);
            foreach ($ips[0] as $ip) {
                if ($ip !== '127.0.0.1') {
                    $found[] = 'line ' . $line . ' address ' . $ip;
                }
            }
            if (preg_match('/\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(com|org|net|gov|edu|io|ai|co|dev|app|xyz|test|example|internal|local)\b/i', $text, $m) === 1) {
                $found[] = 'line ' . $line . ' host ' . $m[0];
            }
        }
        self::assertSame([], $found);
        self::assertTrue($sawLocal, 'the smoke client names the hosts it accepts');
    }
}
