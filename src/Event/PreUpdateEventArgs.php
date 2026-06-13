<?php

declare(strict_types=1);

namespace SymPress\Orm\Event;

final class PreUpdateEventArgs extends LifecycleEventArgs
{
    public function hasChangedField(string $field): bool
    {
        return array_key_exists($field, $this->getChanges());
    }

    public function getNewValue(string $field): mixed
    {
        return $this->getChanges()[$field] ?? null;
    }
}
