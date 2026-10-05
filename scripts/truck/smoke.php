<?php
declare(strict_types=1);

/**
 * HTTP smoke test of the Truck Planner API (docs/truck-planner/04_BACKEND.md 7.2).
 *
 *     php scripts/truck/smoke.php --base-url=http://127.0.0.1:8080 [--no-region] [--keep]
 *
 * It needs a running server (php -S or the web tier) on this machine with its migrations applied. It
 * refuses any other host. It runs every file scripts/truck/smoke/NN_*.php in name order. Each of them
 * returns
 *
 *     function (SmokeClient $c, array &$state): void
 *
 * and does its requests through the client, which fails on any 401, any 5xx, any empty body and any body
 * that is not the house envelope unless the step said it expects that. The first failed file ends the run
 * with exit code 1 and the request, status and body printed.
 *
 * `$state` is shared by the steps, in file order:
 *
 *     no_region   bool   --no-region was given: skip what needs a loaded region
 *     keep        bool   --keep was given
 *     users       [1 => {email, user_id, organization_id}, 2 => {...}]   set by 00_auth.php; the client
 *                 holds their tokens: $c->as(1), $c->as(2), and $c->as(0) for no token
 *
 * A step adds what later steps need under a key of its own (the id of the spot it saved, and so on).
 *
 * Without --keep the run ends by deleting the truck data of both users through the API. The two accounts
 * stay: the API has no way to remove an account.
 */

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/smoke/SmokeClient.php';

$usage = <<<'TXT'
Usage: php scripts/truck/smoke.php --base-url=http://127.0.0.1:8080 [--no-region] [--keep]

  --base-url=URL   the server under test: plain HTTP on 127.0.0.1 or localhost, nothing else
  --no-region      skip the checks that need a loaded region
  --keep           leave the truck data of the two smoke users in place
TXT;

$options = tp_args(['base-url' => 'value', 'no-region' => 'flag', 'keep' => 'flag'], $usage);
if (!isset($options['base-url'])) {
    tp_usage('--base-url is required', $usage);
}

try {
    $client = new SmokeClient((string) $options['base-url']);
} catch (InvalidArgumentException $e) {
    tp_fail('smoke: ' . $e->getMessage(), TP_EXIT_USAGE);
}

$files = glob(__DIR__ . '/smoke/[0-9][0-9]_*.php') ?: [];
sort($files, SORT_STRING);
if ($files === []) {
    tp_fail('smoke: no step files found under scripts/truck/smoke', TP_EXIT_USAGE);
}

$state = [
    'no_region' => isset($options['no-region']),
    'keep' => isset($options['keep']),
    'users' => [],
];

tp_out('Truck Planner smoke test against ' . $options['base-url'] . ($state['no_region'] ? ' (no region)' : ''));

foreach ($files as $file) {
    $name = basename($file);
    $before = $client->requestCount();
    try {
        $step = require $file;
        if (!$step instanceof Closure) {
            throw new SmokeFailure('the file does not return a function (SmokeClient $c, array &$state): void');
        }
        $step($client, $state);
    } catch (Throwable $e) {
        fwrite(STDERR, 'FAILED ' . $name . "\n" . ($e instanceof SmokeFailure ? '' : get_class($e) . ': ') . $e->getMessage() . "\n");
        exit(1);                              // a failed smoke test ends with 1
    }
    tp_out(sprintf('ok  %-28s %3d requests', $name, $client->requestCount() - $before));
}

if (!$state['keep']) {
    try {
        foreach (array_keys($state['users']) as $user) {
            $client->as((int) $user)->post('/api/truck/data/delete', ['confirm' => 'delete my truck data'])->status(200);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "FAILED cleanup\n" . $e->getMessage() . "\n");
        exit(1);                              // a failed smoke test ends with 1
    }
}

tp_out('Smoke test passed: ' . count($files) . ' files, ' . $client->requestCount() . ' requests.');
exit(TP_EXIT_OK);
