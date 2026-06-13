<?php

declare(strict_types=1);

namespace SymPress\Orm\Dbal;

interface ConnectionInterface
{
    public function platform(): SqlPlatformInterface;

    public function tablePrefix(): string;

    public function charsetCollate(): string;

    public function prepare(string $sql, mixed ...$parameters): string;

    public function fetchOne(string $sql, mixed ...$parameters): mixed;

    /** @return list<array<string, mixed>> */
    public function fetchAllAssociative(string $sql, mixed ...$parameters): array;

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): bool|int;

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $criteria
     */
    public function update(string $table, array $data, array $criteria): bool|int;

    /** @param array<string, mixed> $criteria */
    public function delete(string $table, array $criteria): bool|int;

    public function executeStatement(string $sql, mixed ...$parameters): bool|int;

    public function lastInsertId(): int|string|null;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    public function isTransactionActive(): bool;
}
