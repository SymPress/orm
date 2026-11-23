<?php

declare(strict_types=1);

namespace SymPress\Orm\Exception;

final class EntityNotFoundException extends \RuntimeException
{
    /** @param array<string, mixed> $identifier */
    public static function fromClassAndIdentifier(string $className, array $identifier): self
    {
        return new self(sprintf(
            'Entity "%s" with identifier %s was not found.',
            $className,
            (string) json_encode($identifier, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ));
    }
}
