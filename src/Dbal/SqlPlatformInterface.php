<?php

declare(strict_types=1);

namespace SymPress\Orm\Dbal;

interface SqlPlatformInterface
{
    public function quoteIdentifier(string $identifier): string;

    public function parameterPlaceholder(mixed $value): string;

    public function beginTransactionSql(): string;

    public function commitTransactionSql(): string;

    public function rollbackTransactionSql(): string;
}
