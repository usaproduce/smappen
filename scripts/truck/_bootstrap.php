<?php
declare(strict_types=1);

/**
 * Common start of every Truck Planner operator script (docs/truck-planner/04_BACKEND.md 7.2).
 *
 *     require __DIR__ . '/_bootstrap.php';
 *
 * It loads the autoloader and the environment (a .env file when there is one, else the real environment),
 * makes PHP print floats with every digit, lifts the time limit, and gives the scripts one way to read
 * their options and to end.
 *
 * Exit codes: 0 success, 1 usage or input/output error, 2 a check that failed.
 * Run the scripts with the PHP of the web tier, so that numbers come out the same.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Core\Config;

Config::load(dirname(__DIR__, 2));

ini_set('serialize_precision', '-1');
ini_set('precision', '17');
set_time_limit(0);

const TP_EXIT_OK = 0;
const TP_EXIT_USAGE = 1;
const TP_EXIT_FAILED = 2;

/**
 * The options of the command line, checked against what the script accepts.
 *
 * `$spec` maps each option name to "flag" (--dry-run), "value" (--region=dc) or "optional" (--activate or
 * --activate=<version>). The answer holds the options that were given: true for a flag, the text for a
 * value. `--help` prints the usage and ends with 0. An unknown option, a stray word, a flag with a value,
 * a value option without one or an option given twice prints the usage and ends with 1.
 *
 * @param array<string, string> $spec
 * @param list<string>|null $arguments the words after the script name; null reads the command line
 * @return array<string, string|bool>
 */
function tp_args(array $spec, string $usage, ?array $arguments = null): array
{
    if ($arguments === null) {
        $arguments = array_slice($_SERVER['argv'] ?? [], 1);
    }
    $given = [];
    foreach ($arguments as $word) {
        if ($word === '--help' || $word === '-h') {
            echo rtrim($usage), "\n";
            exit(TP_EXIT_OK);
        }
        if (preg_match('/^--([a-z][a-z0-9-]*)(?:=(.*))?$/s', (string) $word, $m) !== 1) {
            tp_usage('unexpected argument: ' . $word, $usage);
        }
        $name = $m[1];
        $value = $m[2] ?? null;
        if (!isset($spec[$name])) {
            tp_usage('unknown option: --' . $name, $usage);
        }
        if (array_key_exists($name, $given)) {
            tp_usage('option given twice: --' . $name, $usage);
        }
        $kind = $spec[$name];
        if ($kind === 'flag' && $value !== null) {
            tp_usage('--' . $name . ' takes no value', $usage);
        }
        if ($kind === 'value' && ($value === null || $value === '')) {
            tp_usage('--' . $name . ' needs a value', $usage);
        }
        $given[$name] = ($value === null || ($kind === 'optional' && $value === '')) ? true : $value;
    }
    return $given;
}

/** Prints the problem and the usage to the error stream and ends with exit code 1. */
function tp_usage(string $problem, string $usage): never
{
    fwrite(STDERR, $problem . "\n\n" . rtrim($usage) . "\n");
    exit(TP_EXIT_USAGE);
}

/** One line of progress on standard output. */
function tp_out(string $line): void
{
    echo $line, "\n";
}

/** Prints a message to the error stream and ends with the given exit code (1 or 2). */
function tp_fail(string $message, int $code = TP_EXIT_USAGE): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}
