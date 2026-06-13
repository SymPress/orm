<?php

declare(strict_types=1);

namespace SymPress\Orm\Dbal;

final readonly class WordPressSqlPlatform implements SqlPlatformInterface
{
    public function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException(sprintf('Invalid SQL identifier "%s".', $identifier));
        }

        return '`' . $identifier . '`';
    }

    public function parameterPlaceholder(mixed $value): string
    {
        if (is_int($value) || is_bool($value)) {
            return '%d';
        }

        if (is_float($value)) {
            return '%f';
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
