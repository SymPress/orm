<?php

declare(strict_types=1);

namespace SymPress\Orm\Metadata;

use SymPress\Orm\Util\NameConverter;

final class EntityClassRegistry
{
    /** @var array<string, list<class-string>>|null */
    private ?array $discovered = null;

    /** @var array<string, list<class-string>> */
    private array $registered = [];

    /**
     * @param array<string, array{path?: string, package?: string, type?: string, entry?: string}> $bundleMetadata
     * @param list<string> $paths
     * @param list<class-string>|array<string, list<class-string>> $classes
     */
    public function __construct(
        private readonly MetadataFactory $metadataFactory,
        private readonly array $bundleMetadata = [],
        private readonly array $paths = [],
        array $classes = [],
        private readonly NameConverter $names = new NameConverter(),
    ) {

        $this->registerConfiguredClasses($classes);
    }

    /**
     * @param class-string|list<class-string> $classes
     */
    public function register(string|array $classes, string $manager = 'default'): void
    {
        foreach ((array) $classes as $class) {
            if (!is_string($class) || $class === '') {
                continue;
            }

            /** @var class-string $class */
            $this->registered[$manager][] = $class;
        }

        $this->registered[$manager] = array_values(array_unique($this->registered[$manager] ?? []));
    }

    /** @return list<class-string> */
    public function classes(?string $manager = null): array
    {
        $groups = $this->groups();

        if ($manager !== null) {
            return $groups[$manager] ?? [];
        }

        $classes = [];

        foreach ($groups as $groupClasses) {
            $classes = [...$classes, ...$groupClasses];
        }

        return array_values(array_unique($classes));
    }

    /** @return array<string, list<class-string>> */
    public function groups(): array
    {
        $groups = $this->discoveredGroups();

        foreach ($this->registered as $manager => $classes) {
            $groups[$manager] = array_values(array_unique([
                ...($groups[$manager] ?? []),
                ...$classes,
            ]));
        }

        ksort($groups);

        return $groups;
    }

    public function managerForClass(string $className): ?string
    {
        foreach ($this->groups() as $manager => $classes) {
            if (in_array($className, $classes, true)) {
                return $manager;
            }
        }

        return null;
    }

    public function findByShortName(string $shortName): ?string
    {
        foreach ($this->classes() as $className) {
            if ($this->names->shortName($className) === $shortName || $className === $shortName) {
                return $className;
            }
        }

        return null;
    }

    /** @return array<string, list<class-string>> */
    private function discoveredGroups(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $groups = [];

        foreach ($this->bundleMetadata as $metadata) {
            $path = is_string($metadata['path'] ?? null) ? $metadata['path'] : null;
            $package = is_string($metadata['package'] ?? null) ? $metadata['package'] : 'default';

            if ($path === null || $path === '') {
                continue;
            }

            $classes = $this->discoverInPath($path . '/src');

            if ($classes === []) {
                continue;
            }

            $manager = $this->managerName($package);
            $groups[$manager] = array_values(array_unique([
                ...($groups[$manager] ?? []),
                ...$classes,
            ]));
        }

        foreach ($this->paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            $groups['default'] = array_values(array_unique([
                ...($groups['default'] ?? []),
                ...$this->discoverInPath($path),
            ]));
        }

        return $this->discovered = $groups;
    }

    /** @return list<class-string> */
    private function discoverInPath(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $classes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $class = $this->classFromFile($file->getPathname());

            if ($class === null || !class_exists($class) || !$this->metadataFactory->hasMetadataFor($class)) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return array_values(array_unique($classes));
    }

    private function classFromFile(string $file): ?string
    {
        $contents = file_get_contents($file);

        if (!is_string($contents) || $contents === '') {
            return null;
        }

        if (!$this->mayContainEntityAttribute($contents)) {
            return null;
        }

        $tokens = token_get_all($contents);
        $namespace = '';
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->readNamespace($tokens, $index + 1);
                continue;
            }

            if ($token[0] !== T_CLASS) {
                continue;
            }

            if ($this->isAnonymousClass($tokens, $index)) {
                continue;
            }

            $class = $this->readClassName($tokens, $index + 1);

            if ($class === null) {
                continue;
            }

            return ltrim($namespace . '\\' . $class, '\\');
        }

        return null;
    }

    private function mayContainEntityAttribute(string $contents): bool
    {
        return str_contains($contents, '#[') && str_contains($contents, 'Entity');
    }

    /** @param list<mixed> $tokens */
    private function readNamespace(array $tokens, int $offset): string
    {
        $namespace = '';

        for ($index = $offset, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if ($token === ';' || $token === '{') {
                break;
            }

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                $namespace .= $token[1];
            }
        }

        return $namespace;
    }

    /** @param list<mixed> $tokens */
    private function readClassName(array $tokens, int $offset): ?string
    {
        for ($index = $offset, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private function isAnonymousClass(array $tokens, int $classIndex): bool
    {
        for ($index = $classIndex - 1; $index >= 0; $index--) {
            $token = $tokens[$index];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) && $token[0] === T_NEW;
        }

        return false;
    }

    private function managerName(string $package): string
    {
        if (!str_contains($package, '/')) {
            return $package;
        }

        return str_replace('/', '-', $package);
    }

    /**
     * @param list<class-string>|array<string, list<class-string>> $classes
     */
    private function registerConfiguredClasses(array $classes): void
    {
        if ($classes === []) {
            return;
        }

        if (array_is_list($classes)) {
            /** @var list<class-string> $classes */
            $this->register($classes);
            return;
        }

        foreach ($classes as $manager => $managerClasses) {
            /** @var list<class-string> $managerClasses */
            $this->register($managerClasses, is_string($manager) ? $manager : 'default');
        }
    }
}
