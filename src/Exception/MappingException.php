<?php

declare(strict_types=1);

namespace SymPress\Orm\Exception;

final class MappingException extends \RuntimeException
{
    /** @param list<string> $errors */
    public static function invalid(array $errors): self
    {
        return new self("Invalid ORM mapping:\n- " . implode("\n- ", $errors));
    }
}
