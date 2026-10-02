<?php

declare(strict_types=1);

// Run in a separate process: current and 0.2.0 ORM classes share their namespace.
require __DIR__ . '/bootstrap.php';
$legacySource = getenv('SYMPRESS_ORM_LEGACY_SOURCE');
if (!is_string($legacySource) || !is_file($legacySource . '/src/Bridge/Migration/SchemaMigrationFactory.php')) {
    throw new RuntimeException('Provide the reviewed ORM 0.2.0 source checkout.');
}
$loader = new Composer\Autoload\ClassLoader();
$loader->addPsr4('SymPress\\Orm\\', $legacySource . '/src');
$loader->register(true);
require dirname(__DIR__) . '/Fixtures/EmailLog.php';
$database = $GLOBALS['wpdb'];
$metadata = new SymPress\Orm\Metadata\MetadataFactory();
$entities = new SymPress\Orm\Metadata\EntityClassRegistry($metadata, classes: [SymPress\Orm\Tests\Fixtures\EmailLog::class]);
$tool = new SymPress\Orm\Schema\SchemaTool($metadata, $entities, new SymPress\Orm\Schema\SchemaSqlGenerator(), $database);
$migration = (new SymPress\Orm\Bridge\Migration\SchemaMigrationFactory($tool))->create('default');
$executor = new SymPress\WordPress\Migration\Infrastructure\WordPressSqlExecutor($database);
if (!$executor->execute($migration->up())) {
    throw new RuntimeException('Legacy schema creation failed.');
}
$manager = new SymPress\Orm\EntityManager($metadata, $entities, new SymPress\Orm\EntityHydrator(), $database);
$manager->persist(new SymPress\Orm\Tests\Fixtures\EmailLog('legacy-020', new DateTimeImmutable('2026-09-01 10:00:00'), 'queued', ['customer' => 'preserved']));
$manager->flush();
$tracker = new SymPress\WordPress\Migration\Infrastructure\MigrationTracker($database, $database->prefix . 'orm_review_state');
$timestamp = '2026-09-01 10:00:00';
if (
    !$tracker->saveRecord(new SymPress\WordPress\Migration\Value\MigrationRecord('default', $migration::class, $migration->getVersion(), $timestamp))
    || !$tracker->appendHistory(new SymPress\WordPress\Migration\Value\MigrationExecution('default', $migration::class, $migration->getVersion(), 'up', $timestamp))
) {
    throw new RuntimeException('Legacy state/history fixture creation failed.');
}
echo "ORM 0.2.0 persisted the legacy row and recorded its anonymous migration identity.\n";
