<?php

declare(strict_types=1);

namespace SymPress\Orm\Dbal;

final readonly class WordPressSqlPlatform implements SqlPlatformInterface
{
    public function quoteIdentifier(string $identifier): string
    {
        // Leading digits are valid in quoted identifiers and in WordPress table prefixes such as "7x_".
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $identifier)) {
            throw new \InvalidArgumentException(sprintf('Invalid SQL identifier "%s".', $identifier));
        }

        return '`' . $identifier . '`';
    }

    public function parameterPlaceholder(mixed $value): string
    {
        if (is_int($value) || is_bool($value)) {
            return '%d';
        }

        return '%s';
    }

    public function beginTransactionSql(): string
    {
        return 'START TRANSACTION';
    }

    public function commitTransactionSql(): string
    {
        return 'COMMIT';
    }

    public function rollbackTransactionSql(): string
    {
        return 'ROLLBACK';
    }
}
