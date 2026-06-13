<?php

declare(strict_types=1);

namespace SymPress\Orm\Collection;

/**
 * @template TKey of array-key
 * @template TValue
 * @extends Collection<TKey, TValue>
 */
final class PersistentCollection extends Collection
{
    private bool $initialized = false;

    /**
     * @param \Closure(): array<TKey, TValue> $loader
     * @param \Closure(): int|null $countLoader
     */
    public function __construct(
        private readonly mixed $owner,
        private readonly string $association,
        private readonly \Closure $loader,
        private readonly ?\Closure $countLoader = null,
    ) {

        parent::__construct();
    }

    public function owner(): mixed
    {
        return $this->owner;
    }

    public function association(): string
    {
        return $this->association;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->elements = ($this->loader)();
        $this->initialized = true;
    }

    public function toArray(): array
    {
        $this->initialize();

        return parent::toArray();
    }

    public function add(mixed $element): void
    {
        $this->initialize();
        parent::add($element);
    }

    public function removeElement(mixed $element): bool
    {
        $this->initialize();

        return parent::removeElement($element);
    }

    public function isEmpty(): bool
    {
        if (!$this->initialized && is_callable($this->countLoader)) {
            return ($this->countLoader)() === 0;
        }

        $this->initialize();

        return parent::isEmpty();
    }

    public function count(): int
    {
        if (!$this->initialized && is_callable($this->countLoader)) {
            return max(0, ($this->countLoader)());
        }

        $this->initialize();

        return parent::count();
    }

    public function getIterator(): \Traversable
    {
        $this->initialize();

        return parent::getIterator();
    }

    public function offsetExists(mixed $offset): bool
    {
        $this->initialize();

        return parent::offsetExists($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $this->initialize();

        return parent::offsetGet($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->initialize();
        parent::offsetSet($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->initialize();
        parent::offsetUnset($offset);
    }
}
