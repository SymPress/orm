<?php

declare(strict_types=1);

namespace SymPress\Orm\Mapping;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PROPERTY)]
final readonly class Cache
{
    public const string READ_ONLY = 'READ_ONLY';
    public const string NONSTRICT_READ_WRITE = 'NONSTRICT_READ_WRITE';

    public function __construct(
        public string $usage = self::READ_ONLY,
        public ?string $region = null,
    ) {
    }
}
