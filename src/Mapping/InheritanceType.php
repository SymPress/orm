<?php

declare(strict_types=1);

namespace SymPress\Orm\Mapping;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class InheritanceType
{
    public const string NONE = 'NONE';
    public const string SINGLE_TABLE = 'SINGLE_TABLE';
    public const string JOINED = 'JOINED';
    public const string TABLE_PER_CLASS = 'TABLE_PER_CLASS';

    public function __construct(public string $value)
    {
    }
}
