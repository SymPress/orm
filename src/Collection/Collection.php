<?php

declare(strict_types=1);

namespace SymPress\Orm\Collection;

/**
 * @template TKey of array-key
 * @template TValue
 * @implements \IteratorAggregate<TKey, TValue>
 * @implements \ArrayAccess<TKey, TValue>
 */
class Collection implements \IteratorAggregate, \Countable, \ArrayAccess
{
    /** @param array<TKey, TValue> $elements */
    public function __construct(protected array $elements = [])
    {
    }

    /** @return array<TKey, TValue> */
    public function toArray(): array
    {
        return $this->elements;
    }

    /** @param TValue $element */
    public function add(mixed $element): void
    {
        $this->elements[] = $element;
    }

    /** @param TValue $element */
    public function removeElement(mixed $element): bool
    {
        foreach ($this->elements as $key => $value) {
            if ($value !== $element) {
                continue;
            }

            unset($this->elements[$key]);

            return true;
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->elements === [];
    }

    public function count(): int
    {
        return count($this->elements);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->elements);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->elements[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->elements[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->elements[] = $value;
            return;
        }

        $this->elements[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->elements[$offset]);
    }
}
