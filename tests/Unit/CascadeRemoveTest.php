<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\EntityManager;
use SymPress\Orm\EntityState;
use SymPress\Orm\Event\Events;
use SymPress\Orm\Event\LifecycleEventArgs;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Tests\Fixtures\Address;
use SymPress\Orm\Tests\Fixtures\Note;
use SymPress\Orm\Tests\Fixtures\Profile;

final class CascadeRemoveTest extends TestCase
{
    private \wpdb $database;
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Answers a SELECT with the rows of the table it reads, narrowed to the requested id, if any.
        $this->database = new class extends \wpdb {
            /** @var array<string, list<array<string, mixed>>> */
            public array $rows = [];

            public function get_results(string $query, string|int $output = ARRAY_A): array
            {
                $id = preg_match("/`id` = '([^']*)'/", $query, $match) === 1 ? $match[1] : null;

                foreach ($this->rows as $table => $rows) {
                    if (str_contains($query, '`' . $table . '`')) {
                        return array_values(array_filter($rows, static fn (array $row): bool => $id === null || $row['id'] === $id));
                    }
                }

                return [];
            }
        };
        $metadata = new MetadataFactory();
        $registry = new EntityClassRegistry($metadata, classes: [Address::class, Note::class, Profile::class]);
        $this->entityManager = new EntityManager($metadata, $registry, new EntityHydrator(), $this->database);
    }

    public function testRemoveThatThrowsLeavesNothingScheduled(): void
    {
        $this->database->rows = [
            'wp_sympress_profiles' => [['id' => 'p1', 'billing_id' => null, 'shipping_id' => 'a1']],
            'wp_sympress_notes' => [['id' => 'n1', 'profile_id' => 'p1']],
            'wp_sympress_addresses' => [['id' => 'a1', 'city' => 'Graz']],
        ];
        $this->entityManager->getEventManager()->addEventListener(Events::PRE_REMOVE, static function (LifecycleEventArgs $args): void {
            if ($args->getEntity() instanceof Address) {
                throw new \RuntimeException('Addresses stay.');
            }
        });
        $profile = $this->entityManager->find(Profile::class, 'p1');
        self::assertInstanceOf(Profile::class, $profile);

        try {
            // The notes are visited before the throwing shipping address.
            $this->entityManager->remove($profile);
            self::fail('Expected the listener to throw.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Addresses stay.', $exception->getMessage());
        }

        $unitOfWork = $this->entityManager->getUnitOfWork();
        self::assertSame([], $unitOfWork->scheduledDeletions());
        self::assertSame(EntityState::MANAGED, $unitOfWork->entityState($profile));
        $this->entityManager->flush();
        self::assertSame([], $this->database->deleted);
    }
}
