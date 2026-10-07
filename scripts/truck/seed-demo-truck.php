<?php
declare(strict_types=1);

/**
 * Writes a demonstration truck for one account (docs/truck-planner/04_BACKEND.md 7.2). Development only.
 *
 *     php scripts/truck/seed-demo-truck.php --email=<user email> [--as-of=YYYY-MM-DD] [--reset]
 *
 * For the organization of that user it writes, through the same services the API uses:
 *
 *   - a truck based in Sterling, Virginia (39.0030, -77.4050), with the profile defaults;
 *   - five saved spots at real places of the Washington DC region, named for what stands there: an
 *     office area on Spring Street in Herndon, a taproom on Overland Drive in Sterling (host: 120 people
 *     in its busiest hour, the truck is its only food), an apartment community on Innovation Avenue in
 *     Sterling, the hospital on Town Center Parkway in Reston, and Reston Town Center;
 *   - twelve logged services in the eight calendar weeks before the week of the as-of date. The orders
 *     of each are its raw prediction times 0.75 + (crc32(spot name . date) % 500) / 1000;
 *   - one planned day, the Thursday on or after the as-of date: the office area 11:00 to 14:00, then the
 *     taproom 17:00 to 20:00.
 *
 * Nothing is random: the same as-of date over the same region data gives the same spots, services and
 * plan (ids apart). The as-of date stands for today; without --as-of it is today in the default time zone.
 *
 * The organization must not have a truck yet. --reset deletes its truck data first.
 *
 * Exit codes: 0 success, 1 usage error, production, an unknown user, an organization that has a truck.
 */

require __DIR__ . '/_bootstrap.php';

use App\Core\Database;
use App\TruckPlanner\Services\DemoTruckSeeder;
use App\TruckPlanner\Services\Support\Clock;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpConflict;
use App\TruckPlanner\Services\Support\TpInvalid;

$usage = <<<'TXT'
Usage: php scripts/truck/seed-demo-truck.php --email=<user email> [--as-of=YYYY-MM-DD] [--reset]

  --email=<user email>   the account whose organization gets the demo truck
  --as-of=YYYY-MM-DD     the date that stands for today (default: today in the default time zone)
  --reset                delete the organization's truck data first
TXT;

$options = tp_args(['email' => 'value', 'as-of' => 'value', 'reset' => 'flag'], $usage);

$seeder = new DemoTruckSeeder();
if (!$seeder->allowed()) {
    tp_fail('seed-demo-truck: development only. APP_ENV is production (or not set).', TP_EXIT_USAGE);
}
if (!isset($options['email'])) {
    tp_usage('--email is required', $usage);
}
$email = (string) $options['email'];
$asOf = isset($options['as-of'])
    ? (string) $options['as-of']
    : (new Clock())->today((string) TpConfig::get('regions.default_timezone'));
try {
    DemoTruckSeeder::dates($asOf);
} catch (TpInvalid $e) {
    tp_usage('--as-of: ' . $e->getMessage(), $usage);
}

$user = Database::getInstance()->fetch('SELECT id, organization_id FROM users WHERE email = ?', [$email]);
if ($user === null) {
    tp_fail('seed-demo-truck: no user with the email ' . $email, TP_EXIT_USAGE);
}
$orgId = (string) ($user['organization_id'] ?? '');
if ($orgId === '') {
    tp_fail('seed-demo-truck: that account has no workspace', TP_EXIT_USAGE);
}

if (isset($options['reset'])) {
    $purge = 'App\\TruckPlanner\\Services\\DataPurgeService';
    if (!class_exists($purge)) {
        tp_fail('--reset needs DataPurgeService', TP_EXIT_USAGE);
    }
    $deleted = (new $purge())->deleteTruckData($orgId);
    $parts = [];
    foreach ($deleted as $what => $count) {
        $parts[] = $count . ' ' . $what;
    }
    tp_out('Deleted: ' . implode(', ', $parts));
}

try {
    $seeded = $seeder->seed($orgId, (string) $user['id'], $asOf);
} catch (TpConflict $e) {
    $hint = $e->getMessage() === DemoTruckSeeder::HAS_TRUCK ? '. Use --reset to replace its data.' : '';
    tp_fail('seed-demo-truck: ' . $e->getMessage() . $hint, TP_EXIT_USAGE);
}

$clock = static function (int $minute): string {
    $past = $minute % 60;
    return sprintf('%02d:%02d', (($minute - $past) / 60) % 24, $past);
};
$profile = $seeded['truck']['profile'];
tp_out('Demo truck "' . $profile['name'] . '" for ' . $email . ', as of ' . $seeded['as_of']);
tp_out(sprintf('  base     %s (%.4F, %.4F), region %s', $profile['base']['address'], $profile['base']['lat'], $profile['base']['lng'], $profile['region_id']));
foreach ($seeded['spots'] as $spot) {
    tp_out('  spot     ' . $spot['name']);
}
foreach ($seeded['services'] as $service) {
    tp_out(sprintf(
        '  service  %s  %s-%s  %-11s  %3d orders (model alone: %s)',
        $service['date'],
        $clock($service['open_minute']),
        $clock($service['close_minute']),
        $service['spot'],
        $service['actual'],
        $service['predicted_raw'] === null ? 'none' : sprintf('%.1f', $service['predicted_raw'])
    ));
}
tp_out('  plan     ' . $seeded['plan']['date'] . ' (result ' . $seeded['plan']['result_state'] . ')');
tp_out(sprintf(
    '  results  truck factor %.4f from %d services',
    $seeded['calibration']['truck_factor'],
    $seeded['calibration']['truck_n']
));
exit(TP_EXIT_OK);
