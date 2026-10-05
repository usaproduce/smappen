<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Support;

use App\Core\Database;

/**
 * A Database that connects to nothing: it records every statement with its bound values and answers
 * reads with what the test prepared. Truck Planner repositories take `?Database $db = null`, so a test
 * passes one of these.
 *
 *     $db = new RecordingDatabase();
 *     $db->when('FROM tp_trucks', ['id' => 't1', ...]);      // every read whose SQL contains the text
 *     $db->queue([['spot_id' => 's1', 'log_count' => 2]]);    // or: answers in the order of the reads
 *     $repo = new TruckRepository($db);
 *     ...
 *     $call = $db->only('UPDATE tp_trucks');                  // the one statement that contains the text
 *     self::assertSame(['1500', 't1', 'org-1'], $call['params']);
 *
 * fetch() answers one row (an array) or null; fetchAll() answers a list of rows. A read that nothing was
 * prepared for answers null or []. Only the methods the repositories may use are replaced: query, fetch,
 * fetchAll and the three transaction calls. insert(), update(), delete() and pdo() still belong to the real
 * class and fail at once here, which is what a repository that used them would deserve.
 */
final class RecordingDatabase extends Database
{
    /** @var list<array{kind: string, sql: string, params: array<int|string, mixed>}> every call, in order */
    public array $calls = [];

    /** @var list<mixed> */
    private array $queued = [];

    /** @var list<array{0: string, 1: mixed}> */
    private array $stubs = [];

    /** @var list<array{0: string, 1: \Throwable}> */
    private array $failures = [];

    private int $depth = 0;

    /** The parent's constructor is private and opens a connection: this one does neither. */
    public function __construct()
    {
    }

    /** Answers of the next reads, first in first out. */
    public function queue(mixed ...$results): self
    {
        foreach ($results as $result) {
            $this->queued[] = $result;
        }
        return $this;
    }

    /** The answer of every read whose SQL contains `$needle` (whitespace is not significant). */
    public function when(string $needle, mixed $result): self
    {
        $this->stubs[] = [self::squash($needle), $result];
        return $this;
    }

    /** Every statement whose SQL contains `$needle` throws. */
    public function failOn(string $needle, ?\Throwable $error = null): self
    {
        $this->failures[] = [self::squash($needle), $error ?? new \RuntimeException('database failure (test)')];
        return $this;
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $this->record('query', $sql, $params);
        return new \PDOStatement();
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $this->record('fetch', $sql, $params);
        $result = $this->answer($sql, null);
        if ($result !== null && !is_array($result)) {
            throw new \LogicException('RecordingDatabase: fetch() answers a row (array) or null');
        }
        return $result;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->record('fetchAll', $sql, $params);
        $result = $this->answer($sql, []);
        if (!is_array($result)) {
            throw new \LogicException('RecordingDatabase: fetchAll() answers a list of rows');
        }
        return $result;
    }

    public function beginTransaction(): void
    {
        if ($this->depth > 0) {
            throw new \LogicException('RecordingDatabase: there is already an active transaction');
        }
        $this->depth = 1;
        $this->calls[] = ['kind' => 'begin', 'sql' => '', 'params' => []];
    }

    public function commit(): void
    {
        $this->depth = 0;
        $this->calls[] = ['kind' => 'commit', 'sql' => '', 'params' => []];
    }

    public function rollback(): void
    {
        $this->depth = 0;
        $this->calls[] = ['kind' => 'rollback', 'sql' => '', 'params' => []];
    }

    /**
     * The calls whose SQL contains `$needle`, in order.
     *
     * @return list<array{kind: string, sql: string, params: array<int|string, mixed>}>
     */
    public function find(string $needle): array
    {
        $needle = self::squash($needle);
        $found = [];
        foreach ($this->calls as $call) {
            if ($call['sql'] !== '' && str_contains($call['sql'], $needle)) {
                $found[] = $call;
            }
        }
        return $found;
    }

    /**
     * The one call whose SQL contains `$needle`. Fails when there is none or more than one.
     *
     * @return array{kind: string, sql: string, params: array<int|string, mixed>}
     */
    public function only(string $needle): array
    {
        $found = $this->find($needle);
        if (count($found) !== 1) {
            throw new \LogicException('RecordingDatabase: ' . count($found) . ' statements contain "' . $needle . '", expected 1');
        }
        return $found[0];
    }

    /**
     * Every SQL statement in order, with whitespace squashed to single spaces.
     *
     * @return list<string>
     */
    public function statements(): array
    {
        $out = [];
        foreach ($this->calls as $call) {
            if ($call['sql'] !== '') {
                $out[] = $call['sql'];
            }
        }
        return $out;
    }

    /**
     * "begin", "commit", "rollback" and the kinds of the statements between them, in order.
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        return array_column($this->calls, 'kind');
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function record(string $kind, string $sql, array $params): void
    {
        $squashed = self::squash($sql);
        $this->calls[] = ['kind' => $kind, 'sql' => $squashed, 'params' => $params];
        foreach ($this->failures as [$needle, $error]) {
            if (str_contains($squashed, $needle)) {
                throw $error;
            }
        }
    }

    private function answer(string $sql, mixed $default): mixed
    {
        $squashed = self::squash($sql);
        foreach ($this->stubs as [$needle, $result]) {
            if (str_contains($squashed, $needle)) {
                return $result;
            }
        }
        if ($this->queued !== []) {
            return array_shift($this->queued);
        }
        return $default;
    }

    private static function squash(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }
}
