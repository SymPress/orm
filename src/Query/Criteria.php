<?php

declare(strict_types=1);

namespace SymPress\Orm\Query;

final class Criteria
{
    /** @var list<string> */
    private array $where = [];

    /** @var array<string, mixed> */
    private array $parameters = [];

    /** @var array<string, 'ASC'|'DESC'|string> */
    private array $orderBy = [];

    private ?int $maxResults = null;
    private ?int $firstResult = null;

    public static function create(): self
    {
        return new self();
    }

    public function where(string $expression): self
    {
        $this->where = [$expression];

        return $this;
    }

    public function andWhere(string $expression): self
    {
        $this->where[] = $expression;

        return $this;
    }

    public function setParameter(string|int $name, mixed $value): self
    {
        $this->parameters[ltrim((string) $name, ':?')] = $value;

        return $this;
    }

    /** @param array<string|int, mixed> $parameters */
    public function setParameters(array $parameters): self
    {
        foreach ($parameters as $name => $value) {
            $this->setParameter($name, $value);
        }

        return $this;
    }

    /** @param array<string, 'ASC'|'DESC'|string> $orderBy */
    public function orderBy(array $orderBy): self
    {
        $this->orderBy = $orderBy;

        return $this;
    }

    public function setMaxResults(?int $maxResults): self
    {
        $this->maxResults = $maxResults;

        return $this;
    }

    public function setFirstResult(?int $firstResult): self
    {
        $this->firstResult = $firstResult;

        return $this;
    }

    /** @return list<string> */
    public function whereExpressions(): array
    {
        return $this->where;
    }

    /** @return array<string, mixed> */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /** @return array<string, 'ASC'|'DESC'|string> */
    public function orderings(): array
    {
        return $this->orderBy;
    }

    public function maxResults(): ?int
    {
        return $this->maxResults;
    }

    public function firstResult(): ?int
    {
        return $this->firstResult;
    }
}
