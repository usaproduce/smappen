<?php
namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;
    private bool $persistent = false;

    private function __construct()
    {
        $host = Config::get('DB_HOST', 'localhost');
        $port = Config::get('DB_PORT', '3306');
        $name = Config::get('DB_NAME', 'smappen');
        $user = Config::get('DB_USER', 'root');
        $pass = Config::get('DB_PASS', '');

        // Persistent connections are a per-FPM-worker pool: reusing the
        // same MySQL handle across requests avoids the ~1ms TCP/auth
        // handshake on every page. CLI scripts (cron workers) get a
        // fresh connection per invocation regardless — the persistent
        // pool only makes sense in long-lived SAPI processes, and
        // keeping CLI off prevents the worker chain from accidentally
        // holding open connections after the script exits.
        //
        // Env switch: DB_PERSISTENT=false reverts to per-request connect
        // (escape hatch if a hosting environment misbehaves under
        // persistent PDO — see docs/infra.md).
        $persistFlag = (string) Config::get('DB_PERSISTENT', 'true');
        $persistWanted = filter_var($persistFlag, FILTER_VALIDATE_BOOLEAN);
        $isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
        $this->persistent = $persistWanted && !$isCli;

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if ($this->persistent) {
            $opts[PDO::ATTR_PERSISTENT] = true;
        }
        try {
            $this->pdo = new PDO($dsn, $user, $pass, $opts);
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
        }
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** True if this handle is using PDO::ATTR_PERSISTENT. Exposed for /api/health. */
    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function insert(string $table, array $data): string
    {
        if (!isset($data['id'])) {
            $data['id'] = self::uuid();
        }
        $cols = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(',', array_map(fn($c) => "`$c`", $cols)),
            implode(',', $placeholders)
        );
        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->execute();
        return $data['id'];
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $k => $v) {
            $sets[] = "`$k` = :set_$k";
            $params[":set_$k"] = $v;
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(',', $sets), $where);
        $stmt = $this->pdo->prepare($sql);
        foreach (array_merge($params, $whereParams) as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function delete(string $table, string $where, array $whereParams = []): int
    {
        $sql = sprintf('DELETE FROM `%s` WHERE %s', $table, $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($whereParams);
        return $stmt->rowCount();
    }

    public function beginTransaction(): void { $this->pdo->beginTransaction(); }
    public function commit(): void { $this->pdo->commit(); }
    public function rollback(): void { $this->pdo->rollBack(); }

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
