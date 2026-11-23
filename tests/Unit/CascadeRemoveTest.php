<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\EntityManager;
use SymPress\Orm\EntityState;
use SymPress\Orm\Event\Events;
use SymPress\Orm\Event\LifecycleEventArgs;
use SymPress\Orm\Exception\EntityNotFoundException;
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

    /** @return iterable<string, array{string, string, list<array{string, string}>}> */
    public static function danglingToOne(): iterable
    {
        yield 'to-one before the to-many' => ['gone', 'a1', [['notes', 'n1'], ['addresses', 'a1'], ['profiles', 'p1']]];
        yield 'to-one after the to-many' => ['a1', 'gone', [['addresses', 'a1'], ['notes', 'n1'], ['profiles', 'p1']]];
    }

    /** @param list<array{string, string}> $deleted */
    #[DataProvider('danglingToOne')]
    public function testCascadedRemoveSkipsAReferenceWhoseRowIsGone(string $billing, string $shipping, array $deleted): void
    {
        $this->database->rows = [
            'wp_sympress_profiles' => [['id' => 'p1', 'billing_id' => $billing, 'shipping_id' => $shipping]],
            'wp_sympress_notes' => [['id' => 'n1', 'profile_id' => 'p1']],
            'wp_sympress_addresses' => [['id' => 'a1', 'city' => 'Graz']],
        ];
        $profile = $this->entityManager->find(Profile::class, 'p1');
        self::assertInstanceOf(Profile::class, $profile);

        $this->entityManager->remove($profile);
        $this->entityManager->flush();

        self::assertSame(
            array_map(static fn (array $row): array => ['table' => 'wp_sympress_' . $row[0], 'where' => ['id' => $row[1]]], $deleted),
            $this->database->deleted,
        );
    }

    public function testCascadedReferenceWhoseRowExistsButFailsToLoadStillThrows(): void
    {
        $this->database->rows = [
            'wp_sympress_profiles' => [['id' => 'p1', 'billing_id' => 'unreadable', 'shipping_id' => null]],
            'wp_sympress_notes' => [['id' => 'n1', 'profile_id' => 'p1']],
        ];
        // The COUNT(*) existence check finds the row that the reference could not load.
        $this->database->countResult = 1;
        $profile = $this->entityManager->find(Profile::class, 'p1');
        self::assertInstanceOf(Profile::class, $profile);

        $this->expectException(EntityNotFoundException::class);

        try {
            $this->entityManager->remove($profile);
        } finally {
            self::assertSame([], $this->entityManager->getUnitOfWork()->scheduledDeletions());
        }
    }

    public function testDirectRemoveOfAReferenceToAMissingRowStillThrows(): void
    {
        $profile = $this->entityManager->getReference(Profile::class, 'missing');

        try {
            $this->entityManager->remove($profile);
            self::fail('Expected EntityNotFoundException.');
        } catch (EntityNotFoundException) {
        }

        self::assertSame([], $this->entityManager->getUnitOfWork()->scheduledDeletions());
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
