<?php
declare(strict_types=1);

/**
 * The daily sweep of Google content (docs/truck-planner/04_BACKEND.md 7.2, DECISIONS section 0).
 *
 *     php scripts/truck/purge-google-cache.php [--dry-run]
 *
 * What Truck Planner fetched from Google and stored is kept for at most 30 days. Requests already remove
 * what they meet on their way; this script removes the rest, for every organization, also for an account
 * that sends no request any more:
 *
 *   drive_legs       cached drive legs older than 30 days are deleted
 *   plan_snapshots   stored plan results that were computed with Google legs more than 30 days ago are
 *                    emptied (the plan itself, its stops and notes, stays)
 *
 * The owner's own data is never touched: drive-time corrections, typed contact details and plans stay.
 * Scout leads are not part of the sweep: of a contact lookup they keep Google's id of the place, which may
 * be kept, and the looked-up contact details are never stored.
 * Running it again finds nothing to do. --dry-run counts what would be removed and changes nothing.
 *
 * It prints one line. A part whose package is not installed is "skipped". Exit codes: 0 success, 1 a
 * usage error or a part that failed (the other parts still ran).
 *
 * Scheduled daily by the deploy runbook (8.5 step 11).
 */

require __DIR__ . '/_bootstrap.php';

use App\TruckPlanner\Services\DataPurgeService;
use App\TruckPlanner\Services\Support\Clock;
use App\TruckPlanner\Services\Support\Redactor;

$usage = <<<'TXT'
Usage: php scripts/truck/purge-google-cache.php [--dry-run]

  --dry-run   count what has passed its 30 days and change nothing
TXT;

$options = tp_args(['dry-run' => 'flag'], $usage);
$dryRun = isset($options['dry-run']);

try {
    $result = (new DataPurgeService())->purgeGoogleCaches($dryRun);
} catch (Throwable $e) {
    tp_fail('purge-google-cache: ' . get_class($e) . ': ' . Redactor::text($e->getMessage()), TP_EXIT_USAGE);
}

$parts = [];
foreach (['drive_legs', 'plan_snapshots'] as $part) {
    if (in_array($part, $result['failed'], true)) {
        $parts[] = $part . '=failed';
    } elseif ($result[$part] === null) {
        $parts[] = $part . '=skipped';
    } else {
        $parts[] = $part . '=' . $result[$part];
    }
}
tp_out((new Clock())->nowUtc()->format('Y-m-d\TH:i:s\Z') . ' purge-google-cache' . ($dryRun ? ' (dry run, nothing changed)' : '') . ': ' . implode(' ', $parts));

if ($result['failed'] !== []) {
    tp_fail('purge-google-cache: failed: ' . implode(', ', $result['failed']) . ' (see the error log)', TP_EXIT_USAGE);
}
exit(TP_EXIT_OK);
