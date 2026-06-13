<?php

declare(strict_types=1);

namespace SymPress\Orm\Event;

interface EventSubscriberInterface
{
    /** @return list<string> */
    public static function getSubscribedEvents(): array;
}
