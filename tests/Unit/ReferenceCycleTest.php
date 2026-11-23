<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\EntityManager;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Tests\Fixtures\EagerNode;
use SymPress\Orm\Tests\Fixtures\Employee;
use SymPress\Orm\Tests\Fixtures\Person;

final class ReferenceCycleTest extends TestCase
{
    private \wpdb $database;
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $metadata = new MetadataFactory();
        $registry = new EntityClassRegistry($metadata, classes: [Person::class, Employee::class, EagerNode::class]);
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

    public function testInheritanceRowsReferencingEachOtherLoadOnce(): void
    {
        $this->database->rows = [
            ['id' => 'p-1', 'kind' => 'person', 'spouse_id' => 'p-2'],
            ['id' => 'p-2', 'kind' => 'employee', 'spouse_id' => 'p-1'],
        ];

        $person = $this->entityManager->find(Person::class, 'p-1');

        self::assertInstanceOf(Person::class, $person);
        self::assertInstanceOf(Employee::class, $person->spouse);
        self::assertSame($person, $person->spouse->spouse);
        self::assertFalse(self::isLazy($person));
        self::assertFalse(self::isLazy($person->spouse));
        self::assertCount(2, $this->database->selects);
    }

    public function testSelfReferencingInheritanceRowLoadsOnce(): void
    {
        $this->database->rows = [['id' => 'p-3', 'kind' => 'employee', 'spouse_id' => 'p-3']];

        $person = $this->entityManager->find(Person::class, 'p-3');

        self::assertInstanceOf(Employee::class, $person);
        self::assertSame($person, $person->spouse);
        self::assertFalse(self::isLazy($person));
        self::assertCount(1, $this->database->selects);
    }

    public function testEagerAssociationCycleLoadsOnce(): void
    {
        $this->database->rows = [
            ['id' => 'n-1', 'partner_id' => 'n-2'],
            ['id' => 'n-2', 'partner_id' => 'n-1'],
        ];

        $node = $this->entityManager->find(EagerNode::class, 'n-1');

        self::assertInstanceOf(EagerNode::class, $node);
        self::assertInstanceOf(EagerNode::class, $node->partner);
        self::assertSame($node, $node->partner->partner);
        self::assertFalse(self::isLazy($node));
        self::assertCount(2, $this->database->selects);
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

    private static function isLazy(object $entity): bool
    {
        return (new \ReflectionClass($entity))->isUninitializedLazyObject($entity);
    }
}
