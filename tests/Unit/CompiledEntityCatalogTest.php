<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\Compiler\EntityCatalogPass;
use SymPress\Orm\Metadata\CompiledEntityCatalog;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Tests\Fixtures\Animal;
use SymPress\Orm\Tests\Fixtures\CachedAuthor;
use SymPress\Orm\Tests\Fixtures\CatalogEmbeddedEntity;
use SymPress\Orm\Tests\Fixtures\CatalogAddress;
use SymPress\Orm\Tests\Fixtures\CatalogMappedBase;
use Symfony\Component\Config\Resource\FileResource;
use SymPress\Orm\Tests\Fixtures\Dog;
use SymPress\Orm\Tests\Fixtures\EmailLog;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;

final class CompiledEntityCatalogTest extends TestCase
{
    public function testCompiledGraphsRetainAssociationsAndInheritanceWithoutReflection(): void
    {
        $container = $this->build(['animals' => [Animal::class], 'authors' => [CachedAuthor::class]]);
        $factory = $container->get(MetadataFactory::class);
        $registry = $container->get(EntityClassRegistry::class);
        self::assertInstanceOf(MetadataFactory::class, $factory);
        self::assertInstanceOf(EntityClassRegistry::class, $registry);
        self::assertSame(['animals' => [Animal::class], 'authors' => [CachedAuthor::class]], $registry->groups());
        self::assertSame('dog', $factory->getMetadataFor(Dog::class)->discriminatorValue);
        $author = $factory->getMetadataFor(CachedAuthor::class);
        self::assertSame('author_articles', $author->associationForProperty('articles')?->cacheRegion);
        $target = $author->associationForProperty('articles')?->targetEntity;
        self::assertNotNull($target);
        self::assertTrue($factory->hasMetadataFor($target));
        self::assertSame($target, $factory->getMetadataFor($target)->className);
        self::assertSame([], $factory->mappingResources());
        $factory->refresh(Dog::class);
        self::assertSame('dog', $factory->getMetadataFor(Dog::class)->discriminatorValue);
        self::assertNotSame([], $factory->mappingResources());
    }

    public function testEmbeddablesAndMappedSuperclassesAreCompiledWithSourceResources(): void
    {
        $container = $this->build(['embedded' => [CatalogEmbeddedEntity::class]]);
        $factory = $container->get(MetadataFactory::class);
        self::assertInstanceOf(MetadataFactory::class, $factory);
        $metadata = $factory->getMetadataFor(CatalogEmbeddedEntity::class);
        self::assertSame(['id'], $metadata->identifier);
        self::assertSame('address_city', $metadata->columnForProperty('address.city')?->columnName);
        self::assertSame(CatalogAddress::class, $metadata->embeddeds[0]->className);
        $resources = [];
        foreach ($container->getResources() as $resource) {
            if ($resource instanceof FileResource) {
                $resources[] = $resource->getResource();
            }
        }
        self::assertContains((new \ReflectionClass(CatalogAddress::class))->getFileName(), $resources);
        self::assertContains((new \ReflectionClass(CatalogMappedBase::class))->getFileName(), $resources);
        self::assertSame([], $factory->mappingResources());
    }

    public function testConfiguredClassAndManagerParameterAliasesResolveBeforeCompilation(): void
    {
        $container = $this->build(['%configured.manager%' => ['%configured.entity%']]);
        $registry = $container->get(EntityClassRegistry::class);
        self::assertInstanceOf(EntityClassRegistry::class, $registry);
        self::assertSame([EmailLog::class], $registry->classes('configured'));
    }

    public function testFreshProcessDumpUsesCatalogWithoutAutoloadingEntities(): void
    {
        $container = $this->build(['mail' => [EmailLog::class]]);
        $file = tempnam(sys_get_temp_dir(), 'orm-catalog-');
        self::assertIsString($file);
        file_put_contents($file, (new PhpDumper($container))->dump(['class' => 'ReviewOrmCompiledContainer']));
        $script = <<<'SCRIPT'
require $argv[1];
require $argv[2];
$c = new ReviewOrmCompiledContainer();
$f = $c->get(SymPress\Orm\Metadata\MetadataFactory::class);
$r = $c->get(SymPress\Orm\Metadata\EntityClassRegistry::class);
$class = 'SymPress\\Orm\\Tests\\Fixtures\\EmailLog';
if (class_exists($class, false)) { throw new RuntimeException('Unexpected preloaded entity'); }
if ($r->classes('mail') !== [$class] || !$f->hasMetadataFor($class)) { throw new RuntimeException('Missing compiled entity'); }
if ($f->getMetadataFor($class)->tableName !== 'sympress_mailer_logs' || $f->mappingResources() !== []) { throw new RuntimeException('Reflection fallback'); }
if (class_exists($class, false)) { throw new RuntimeException('Entity source autoloaded'); }
echo 'fresh process compiled mapping without entity source PASS';
SCRIPT;
        try {
            $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__) . '/bootstrap.php', $file]);
            $process->mustRun();
            self::assertStringContainsString('PASS', $process->getOutput());
        } finally {
            (new Filesystem())->remove($file);
        }
    }

    public function testDynamicRegistrationAndExplicitDiscoveryRefreshRemainAvailable(): void
    {
        $factory = new MetadataFactory(catalog: new CompiledEntityCatalog());
        $registry = new EntityClassRegistry($factory, paths: [dirname(__DIR__) . '/Fixtures'], catalog: new CompiledEntityCatalog());
        self::assertSame([], $registry->classes());
        $registry->register(EmailLog::class, 'manual');
        self::assertSame([EmailLog::class], $registry->classes('manual'));
        self::assertSame('sympress_mailer_logs', $factory->getMetadataFor(EmailLog::class)->tableName);
        $registry->refreshDiscovery();
        self::assertContains(EmailLog::class, $registry->classes('default'));
    }

    public function testDiscoveryDoesNotFollowSymlinkEntitySources(): void
    {
        $root = sys_get_temp_dir() . '/orm-links-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        symlink(dirname(__DIR__) . '/Fixtures/EmailLog.php', $root . '/EmailLog.php');
        symlink(dirname(__DIR__) . '/Fixtures', $root . '/outside');
        try {
            $registry = new EntityClassRegistry(new MetadataFactory(), paths: [$root]);
            self::assertSame([], $registry->classes());
            $linkedRoot = new EntityClassRegistry(new MetadataFactory(), paths: [$root . '/outside']);
            self::assertSame([], $linkedRoot->classes());
        } finally {
            (new Filesystem())->remove($root);
        }
    }

    public function testCompiledCatalogRejectsMismatchedMappingKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CompiledEntityCatalog(metadata: [Dog::class => (new MetadataFactory())->getMetadataFor(EmailLog::class)]);
    }

    /** @param array<string, list<class-string>> $classes */
    private function build(array $classes): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('configured.manager', 'configured');
        $container->setParameter('configured.entity', EmailLog::class);
        $container->setParameter('kernel.bundles_metadata', []);
        $container->setParameter('orm.entity_paths', []);
        $container->setParameter('orm.entity_classes', $classes);
        $container->setDefinition(MetadataFactory::class, (new Definition(MetadataFactory::class))->setPublic(true));
        $container->setDefinition(EntityClassRegistry::class, (new Definition(EntityClassRegistry::class, [new \Symfony\Component\DependencyInjection\Reference(MetadataFactory::class)]))->setPublic(true));
        $container->addCompilerPass(new EntityCatalogPass());
        $container->compile(true);
        return $container;
    }
}
