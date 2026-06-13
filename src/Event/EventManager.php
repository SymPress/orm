<?php

declare(strict_types=1);

namespace SymPress\Orm\Event;

final class EventManager
{
    /** @var array<string, list<callable(object): void>> */
    private array $listeners = [];

    public function addEventListener(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function addEventSubscriber(EventSubscriberInterface $subscriber): void
    {
        foreach ($subscriber::getSubscribedEvents() as $event) {
            $listener = [$subscriber, $event];

            if (is_callable($listener)) {
                $this->addEventListener($event, $listener);
            }
        }
    }

    public function dispatch(string $event, object $args): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($args);
        }
    }
}
