<?php

declare(strict_types=1);

namespace SymPress\Orm\Exception;

final class PessimisticLockException extends \RuntimeException
{
    public static function transactionRequired(): self
    {
        return new self('Pessimistic locks require an active transaction.');
    }
}
