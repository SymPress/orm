<?php

declare(strict_types=1);

namespace SymPress\Orm\Event;

final readonly class Events
{
    public const string PRE_PERSIST = 'prePersist';
    public const string POST_PERSIST = 'postPersist';
    public const string PRE_UPDATE = 'preUpdate';
    public const string POST_UPDATE = 'postUpdate';
    public const string PRE_REMOVE = 'preRemove';
    public const string POST_REMOVE = 'postRemove';
    public const string POST_LOAD = 'postLoad';
    public const string PRE_FLUSH = 'preFlush';
    public const string ON_FLUSH = 'onFlush';
    public const string POST_FLUSH = 'postFlush';
    public const string ON_CLEAR = 'onClear';

    private function __construct()
    {
    }
}
