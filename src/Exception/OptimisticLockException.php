<?php

declare(strict_types=1);

namespace SymPress\Orm\Exception;

final class OptimisticLockException extends \RuntimeException
{
    public static function lockFailed(object|string $entity): self
    {
        $className = is_object($entity) ? $entity::class : $entity;

        return new self(sprintf('The optimistic lock on entity "%s" failed.', $className));
    }

    public static function versionMismatch(object $entity, mixed $expected, mixed $actual): self
    {
        return new self(sprintf(
            'The optimistic lock on entity "%s" failed: expected version "%s", actual version "%s".',
            $entity::class,
            (string) $expected,
            (string) $actual,
        ));
    }

    public static function notVersioned(object|string $entity): self
    {
        $className = is_object($entity) ? $entity::class : $entity;

        return new self(sprintf('Entity "%s" is not versioned and cannot use optimistic locking.', $className));
    }
}
