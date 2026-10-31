<?php

/**
 * Обёртка над PDO/SQLite: подготовленные запросы, транзакции, миграции.
 *
 * База — локальное состояние панели (журнал, токены, снимки). Источник истины
 * по DNS — API Fornex, а не эта база: расхождение базы и зоны ничего не ломает.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Database;

use PDO;
use PDOStatement;
use RuntimeException;

final class Database
{
    private PDO $pdo;

    public function __construct(string $path, int $busyTimeout = 5000)
    {
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Не удалось создать каталог базы: %s', $dir));
        }

        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = ' . max(0, $busyTimeout));
    }

    /** @param array<string|int, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        $rows = $this->run($sql, $params)->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @param array<string|int, mixed> $params */
    public function exec(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /** @param array<string, mixed> $row */
    public function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders),
        );

        $this->run($sql, $row);

        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $work($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** Применяет неприменённые .sql из каталога миграций по имени файла. */
    public function migrate(string $directory): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                name TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )',
        );

        $applied = array_column($this->all('SELECT name FROM schema_migrations'), 'name');
        $files = glob(rtrim($directory, '/') . '/*.sql') ?: [];
        sort($files);

        $done = [];

        foreach ($files as $file) {
            $name = basename($file);

            if (in_array($name, $applied, true)) {
                continue;
            }

            $sql = (string) file_get_contents($file);
            $this->pdo->exec($sql);
            $this->insert('schema_migrations', ['name' => $name, 'applied_at' => gmdate('c')]);
            $done[] = $name;
        }

        return $done;
    }
}
