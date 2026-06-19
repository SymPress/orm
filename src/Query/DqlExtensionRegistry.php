<?php

declare(strict_types=1);

namespace SymPress\Orm\Query;

final class DqlExtensionRegistry
{
    /** @var array<string, callable(string): string> */
    private array $functions = [];

    /** @var list<callable(string): string> */
    private array $outputWalkers = [];

    public function registerFunction(string $name, callable $compiler): void
    {
        $this->functions[strtoupper($name)] = $compiler;
    }

    public function addOutputWalker(callable $walker): void
    {
        $this->outputWalkers[] = $walker;
    }

    public function hasOutputWalkers(): bool
    {
        return $this->outputWalkers !== [];
    }

    public function compileFunctions(string $expression): string
    {
        if ($this->functions === []) {
            return $expression;
        }

        return preg_replace_callback(
            '/\b([A-Z_][A-Z0-9_]*)\s*\(([^()]*)\)/i',
            function (array $matches): string {
                $compiler = $this->functions[strtoupper($matches[1])] ?? null;

                if (!is_callable($compiler)) {
                    return $matches[0];
                }

                return $compiler(trim($matches[2]));
            },
            $expression,
        ) ?? $expression;
    }

    public function applyOutputWalkers(string $sql): string
    {
        foreach ($this->outputWalkers as $walker) {
            $sql = $walker($sql);
        }

        return $sql;
    }
}
