<?php

declare(strict_types=1);

namespace SymPress\Orm\Cache;

interface CacheInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function delete(string $key): void;

    public function clear(): void;
}
