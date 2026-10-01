<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class KernelCatalogTest extends TestCase
{
    public function testFreshKernelProcessesDiscoverBuildAndInvalidateTheCompiledCatalog(): void
    {
        $root = sys_get_temp_dir() . '/orm-kernel-' . bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir([$root . '/config', $root . '/entities', $root . '/bundle/src'], 0700);
        file_put_contents($root . '/bundle/composer.json', '{}');
        file_put_contents($root . '/composer.json', '{}');
        file_put_contents($root . '/config/services.yaml', "parameters:\n    orm.entity_paths: ['%kernel.project_dir%/entities']\nservices:\n    SymPress\\Orm\\Metadata\\MetadataFactory:\n        public: true\n");
        $this->entity($root . '/entities/First.php', 'First', 'first_table');
        $this->entity($root . '/bundle/src/Bundled.php', 'Bundled', 'bundled_table');
        $script = <<<'SCRIPT'
require $argv[1];
$root = $argv[2];
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'ReviewCatalog\\')) { return; }
    $name = substr($class, strlen('ReviewCatalog\\'));
    foreach ([$root.'/entities/', $root.'/entities/nested/', $root.'/bundle/src/'] as $path) {
        if (is_file($path.$name.'.php')) { require $path.$name.'.php'; return; }
    }
});
$bundles = new SymPress\Kernel\Bundle\BundleRegistry();
$bundle = new SymPress\Orm\OrmBundle();
$ormRoot = dirname((new ReflectionClass($bundle))->getFileName(), 2);
$bundles->add(new SymPress\Kernel\Bundle\BundleMetadata('sympress/orm', 'library', 'orm', $ormRoot, $ormRoot.'/composer.json', $bundle));
$bundles->add(new SymPress\Kernel\Bundle\BundleMetadata('review/catalog', 'library', '', $root.'/bundle', $root.'/bundle/composer.json', new SymPress\Orm\Tests\Fixtures\CatalogBundle($root.'/bundle')));
$kernel = new SymPress\Kernel\Kernel\SiteKernel($root, 'test', true, null, SymPress\Kernel\WpContext::new());
$c = $kernel->createContainer();
$hit = $kernel->tryUseRuntimeContainer($c, $bundles);
if (!$hit) {
    $files = $kernel->configureContainer($c->builder(), $c, $bundles);
    $kernel->createRuntimeContainer($c, $bundles, $files);
}
$r = $c->get(SymPress\Orm\Metadata\EntityClassRegistry::class);
$f = $c->get(SymPress\Orm\Metadata\MetadataFactory::class);
$tables = [];
foreach ($r->classes() as $class) {
    if (!$f->hasMetadataFor($class)) { throw new RuntimeException('Missing compiled mapping'); }
    $tables[$class] = $f->getMetadataFor($class)->tableName;
}
if ($f->mappingResources() !== []) { throw new RuntimeException('Runtime reflection'); }
if ($hit) {
    foreach ($r->classes() as $class) {
        if (class_exists($class, false)) { throw new RuntimeException('Warm entity autoload'); }
    }
}
echo json_encode(['hit'=>$hit,'groups'=>$r->groups(),'tables'=>$tables,'class'=>$c->getParameter('kernel.container_class')], JSON_THROW_ON_ERROR);
SCRIPT;
        try {
            $first = $this->consume($script, $root);
            self::assertFalse($first['hit']);
            self::assertSame(['ReviewCatalog\\Bundled'], $first['groups']['review-catalog']);
            self::assertSame(['ReviewCatalog\\First'], $first['groups']['default']);
            $warm = $this->consume($script, $root);
            self::assertTrue($warm['hit']);
            self::assertSame($first['class'], $warm['class']);
            mkdir($root . '/entities/nested', 0700);
            $this->entity($root . '/entities/nested/Second.php', 'Second', 'second_table');
            $added = $this->consume($script, $root);
            self::assertFalse($added['hit']);
            self::assertSame('second_table', $added['tables']['ReviewCatalog\\Second']);
            self::assertNotSame($first['class'], $added['class']);
            self::assertTrue($this->consume($script, $root)['hit']);
            $file = $root . '/entities/First.php';
            $mtime = filemtime($file);
            $this->entity($file, 'First', 'other_table');
            self::assertIsInt($mtime);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- preserve timestamp for invalidation regression.
            touch($file, $mtime);
            $changed = $this->consume($script, $root);
            self::assertFalse($changed['hit']);
            self::assertSame('other_table', $changed['tables']['ReviewCatalog\\First']);
            self::assertNotSame($added['class'], $changed['class']);
            $filesystem->remove($root . '/entities/nested/Second.php');
            $removed = $this->consume($script, $root);
            self::assertFalse($removed['hit']);
            self::assertArrayNotHasKey('ReviewCatalog\\Second', $removed['tables']);
            self::assertTrue($this->consume($script, $root)['hit']);
            $immutable = ['SYMPRESS_KERNEL_IMMUTABLE_CACHE' => '1', 'SYMPRESS_KERNEL_BUILD_ID' => 'orm-catalog-immutable-proof'];
            self::assertFalse($this->consume($script, $root, $immutable)['hit']);
            // Explicit immutable policy can read trusted mappings even when source is unavailable.
            // Mutating an immutable release without changing its build ID is outside that policy.
            $filesystem->remove([$root . '/entities', $root . '/bundle/src']);
            $withoutSources = $this->consume($script, $root, $immutable);
            self::assertTrue($withoutSources['hit']);
            self::assertSame($removed['groups'], $withoutSources['groups']);
            self::assertSame($removed['tables'], $withoutSources['tables']);
        } finally {
            $filesystem->remove($root);
        }
    }

    private function entity(string $file, string $name, string $table): void
    {
        file_put_contents($file, '<?php namespace ReviewCatalog; #[\\SymPress\\Orm\\Mapping\\Entity(table: ' . var_export($table, true) . ')] final class ' . $name . ' { #[\\SymPress\\Orm\\Mapping\\Id] #[\\SymPress\\Orm\\Mapping\\Column] public string $id; }');
    }

    /** @param array<string, string> $environment
     * @return array{hit: bool, groups: array<string, list<string>>, tables: array<string, string>, class: string} */
    private function consume(string $script, string $root, array $environment = []): array
    {
        $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__) . '/bootstrap.php', $root], env: $environment);
        $process->mustRun();
        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
