<?php

declare(strict_types=1);

namespace SymPress\Orm\Query;

/** @internal */
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

        // Functions are matched in code only; a compiler receives its arguments with their literals restored.
        $mask = LiteralMask::mask($expression);

        return $mask->restore(preg_replace_callback(
            '/\b([A-Z_][A-Z0-9_]*)\s*\(([^()]*)\)/i',
            function (array $matches) use ($mask): string {
                $compiler = $this->functions[strtoupper($matches[1])] ?? null;

                if (!is_callable($compiler)) {
                    return $matches[0];
                }

                return $compiler(trim($mask->restore($matches[2])));
            },
            $mask->code,
        ) ?? $mask->code);
    }

    public function applyOutputWalkers(string $sql): string
    {
        foreach ($this->outputWalkers as $walker) {
            $sql = $walker($sql);
        }

        return $sql;
    }
}
