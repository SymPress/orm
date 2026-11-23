<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\EntityManager;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Tests\Fixtures\EagerNode;

final class ReferenceCycleTest extends TestCase
{
    private \wpdb $database;
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $metadata = new MetadataFactory();
        $registry = new EntityClassRegistry($metadata, classes: [EagerNode::class]);
        // Answers each SELECT with the rows whose identifier it names, and stops a runaway recursion.
        $this->database = new class extends \wpdb {
            /** @var list<string> */
            public array $selects = [];

            /** @var list<array<string, mixed>> */
            public array $rows = [];

            public function get_results(string $query, string|int $output = ARRAY_A): array
            {
                $this->selects[] = $query;

                if (count($this->selects) > 10) {
                    throw new \RuntimeException('Recursive loading: more than 10 SELECTs.');
                }

                return array_values(array_filter($this->rows, static fn (array $row): bool => str_contains($query, "'" . $row['id'] . "'")));
            }
        };
        $this->entityManager = new EntityManager($metadata, $registry, new EntityHydrator(), $this->database);
    }

    public function testFlushWritesKeysOfEntitiesReferencingEachOther(): void
    {
        $first = new EagerNode();
        $first->id = 'n-1';
        $second = new EagerNode();
        $second->id = 'n-2';
        $first->partner = $second;
        $second->partner = $first;

        $this->entityManager->persist($first);
        $this->entityManager->persist($second);
        $this->entityManager->flush();

        self::assertSame(
            [['id' => 'n-1', 'partner_id' => 'n-2'], ['id' => 'n-2', 'partner_id' => 'n-1']],
            array_column($this->database->inserted, 'data'),
        );
    }
}
