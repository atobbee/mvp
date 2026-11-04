<?php
declare(strict_types=1);

namespace AtoBbee;

use PDO;

/** Thin PDO wrapper. Always prepared statements, never string-built SQL with user input. */
final class Db
{
    public readonly PDO $pdo;

    public function __construct(string $dsn, ?string $user = null, ?string $pass = null)
    {
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        if (str_starts_with($dsn, 'sqlite:')) {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        }
    }

    public function all(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public function run(string $sql, array $params = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(',', $cols),
            implode(',', array_map(fn($c) => ':' . $c, $cols))
        );
        $this->run($sql, $data);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(string $table, int $id, array $data): void
    {
        $set = implode(',', array_map(fn($c) => "$c = :$c", array_keys($data)));
        $this->run("UPDATE $table SET $set WHERE id = :__id", $data + ['__id' => $id]);
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $r = $fn($this);
            $this->pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
