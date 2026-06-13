<?php

declare(strict_types=1);

namespace SymPress\Orm\Dbal;

final class WpdbConnection implements ConnectionInterface
{
    private SqlPlatformInterface $platform;

    private int $transactionNesting = 0;

    public function __construct(
        private ?\wpdb $database = null,
        ?SqlPlatformInterface $platform = null,
    ) {

        $this->platform = $platform ?? new WordPressSqlPlatform();
    }

    public function platform(): SqlPlatformInterface
    {
        return $this->platform;
    }

    public function tablePrefix(): string
    {
        return $this->database()->prefix;
    }

    public function charsetCollate(): string
    {
        return $this->database()->get_charset_collate();
    }

    public function prepare(string $sql, mixed ...$parameters): string
    {
        if ($parameters === []) {
            return $sql;
        }

        return $this->database()->prepare($sql, ...$parameters);
    }

    public function fetchOne(string $sql, mixed ...$parameters): mixed
    {
        return $this->database()->get_var($this->prepare($sql, ...$parameters));
    }

    public function fetchAllAssociative(string $sql, mixed ...$parameters): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->database()->get_results($this->prepare($sql, ...$parameters), $this->arrayOutput());

        return $rows;
    }

    public function insert(string $table, array $data): bool|int
    {
        return $this->database()->insert($table, $data);
    }

    public function update(string $table, array $data, array $criteria): bool|int
    {
        return $this->database()->update($table, $data, $criteria);
    }

    public function delete(string $table, array $criteria): bool|int
    {
        return $this->database()->delete($table, $criteria);
    }

    public function executeStatement(string $sql, mixed ...$parameters): bool|int
    {
        return $this->database()->query($this->prepare($sql, ...$parameters));
    }

    public function lastInsertId(): int|string
    {
        return $this->database()->insert_id;
    }

    public function beginTransaction(): void
    {
        if ($this->transactionNesting === 0) {
            $this->executeStatement($this->platform->beginTransactionSql());
        }

        $this->transactionNesting++;
    }

    public function commit(): void
    {
        if ($this->transactionNesting <= 0) {
            return;
        }

        $this->transactionNesting--;

        if ($this->transactionNesting === 0) {
            $this->executeStatement($this->platform->commitTransactionSql());
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionNesting <= 0) {
            $this->executeStatement($this->platform->rollbackTransactionSql());
            return;
        }

        $this->transactionNesting = 0;
        $this->executeStatement($this->platform->rollbackTransactionSql());
    }

    public function isTransactionActive(): bool
    {
        return $this->transactionNesting > 0;
    }

    private function database(): \wpdb
    {
        if ($this->database instanceof \wpdb) {
            return $this->database;
        }

        $database = $GLOBALS['wpdb'] ?? null;

        if ($database instanceof \wpdb) {
            return $this->database = $database;
        }

        throw new \RuntimeException('Global $wpdb is not available.');
    }

    private function arrayOutput(): string
    {
        return defined('ARRAY_A') ? constant('ARRAY_A') : 'ARRAY_A';
    }
}
