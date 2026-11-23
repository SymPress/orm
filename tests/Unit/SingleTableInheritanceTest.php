<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\Cache\ArrayCache;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\EntityManager;
use SymPress\Orm\Exception\EntityNotFoundException;
use SymPress\Orm\Metadata\AssociationMetadata;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Tests\Fixtures\Car;
use SymPress\Orm\Tests\Fixtures\Garage;
use SymPress\Orm\Tests\Fixtures\Motorized;
use SymPress\Orm\Tests\Fixtures\SportsCar;
use SymPress\Orm\Tests\Fixtures\Trailer;
use SymPress\Orm\Tests\Fixtures\Vehicle;

final class SingleTableInheritanceTest extends TestCase
{
    private const string CARS = "`kind` IN ('car', 'sports_car')";

    private \wpdb $database;

    protected function setUp(): void
    {
        parent::setUp();

        // Records every query and answers a SELECT with the rows its id and discriminator filters, if any, let through.
        $this->database = new class extends \wpdb {
            /** @var list<string> */
            public array $selects = [];

            /** @var list<array<string, mixed>> */
            public array $garages = [];

            public function get_results(string $query, string|int $output = ARRAY_A): array
            {
                $this->selects[] = $query;

                if (str_contains($query, 'WHERE 1 = 0')) {
                    return [];
                }

                $kinds = preg_match('/`kind` IN \(([^)]*)\)/', $query, $match) === 1
                    ? array_map(static fn (string $kind): string => trim($kind, " '"), explode(',', $match[1]))
                    : null;
                $id = preg_match("/`id` = '([^']*)'/", $query, $match) === 1 ? $match[1] : null;

                return array_values(array_filter(
                    str_contains($query, 'FROM `wp_sympress_garages`') ? $this->garages : $this->resultRows,
                    static fn (array $row): bool => ($kinds === null || in_array($row['kind'] ?? null, $kinds, true))
                        && ($id === null || $row['id'] === $id),
                ));
            }

            public function get_var(string $query): int
            {
                $this->queries[] = $query;

                return $this->countResult;
            }
        };
        $this->database->resultRows = [
            ['id' => 'v1', 'kind' => 'vehicle', 'garage_id' => 'g1'],
            ['id' => 'c1', 'kind' => 'car', 'garage_id' => 'g1'],
            ['id' => 's1', 'kind' => 'sports_car', 'garage_id' => 'g1'],
        ];
    }

    public function testRootQuerySeesEveryRow(): void
    {
        self::assertCount(3, $this->manager()->getRepository(Vehicle::class)->findAll());
        self::assertSame(['SELECT `e`.* FROM `wp_sympress_vehicles` `e`'], $this->database->selects);
    }

    public function testSubclassQuerySeesItsOwnAndItsSubclassRows(): void
    {
        $manager = $this->manager();

        self::assertSame(['c1', 's1'], $this->ids($manager->getRepository(Car::class)->findAll()));
        self::assertSame(['s1'], $this->ids($manager->getRepository(SportsCar::class)->findAll()));
        self::assertSame(
            [
                'SELECT `e`.* FROM `wp_sympress_vehicles` `e` WHERE `e`.' . self::CARS,
                "SELECT `e`.* FROM `wp_sympress_vehicles` `e` WHERE `e`.`kind` IN ('sports_car')",
            ],
            $this->database->selects,
        );

        // The discriminator values are bound after the WHERE values and before the HAVING values.
        $compiled = $manager->createQueryBuilder()->select('c.id')->from(Car::class, 'c')
            ->where('c.id = :a OR c.id = :b')->groupBy('c.id')->having('COUNT(c.id) > :min')
            ->setParameters(['a' => 'c1', 'b' => 's1', 'min' => 0])
            ->compile();
        self::assertSame(
            'SELECT `c`.`id` FROM `wp_sympress_vehicles` `c` WHERE (`c`.`id` = %s OR `c`.`id` = %s) AND `c`.`kind` IN (%s, %s)'
            . ' GROUP BY `c`.`id` HAVING COUNT(`c`.`id`) > %d',
            $compiled->sql,
        );
        self::assertSame(['c1', 's1', 'car', 'sports_car', 0], $compiled->parameters);
    }

    public function testJoinedSubclassIsFiltered(): void
    {
        $manager = $this->manager();

        self::assertStringEndsWith(
            "INNER JOIN `wp_sympress_vehicles` `c` ON `c`.`garage_id` = `g`.`id` AND (`c`.`id` <> 'x') AND `c`." . self::CARS,
            $manager->createQuery('SELECT g FROM Garage g JOIN g.cars c WITH c.id <> :id', ['id' => 'x'])->getSQL(),
        );
        self::assertStringEndsWith(
            'LEFT JOIN `wp_sympress_vehicles` `r` ON `r`.`id` = `r_join`.`vehicle_id` AND `r`.' . self::CARS,
            $manager->createQueryBuilder()->from(Garage::class, 'g')->leftJoin('g.rentals', 'r')->getQuery()->getSQL(),
        );
    }

    public function testDqlUpdateAndDeleteOnASubclassTouchOnlyItsRows(): void
    {
        $manager = $this->manager();

        self::assertSame(
            "UPDATE `wp_sympress_vehicles` `c` SET `c`.`garage_id` = NULL WHERE (`c`.`id` = 'v1' OR `c`.`id` = 'c1') AND `c`." . self::CARS,
            $manager->createQuery('UPDATE Car c SET c.garage = NULL WHERE c.id = :a OR c.id = :b', ['a' => 'v1', 'b' => 'c1'])->getSQL(),
        );
        self::assertSame('DELETE `c` FROM `wp_sympress_vehicles` `c` WHERE `c`.' . self::CARS, $manager->createQuery('DELETE FROM Car c')->getSQL());
        self::assertSame('DELETE `v` FROM `wp_sympress_vehicles` `v`', $manager->createQuery('DELETE FROM Vehicle v')->getSQL());
    }

    public function testClassWithoutItsOwnDiscriminatorValueSeesOnlyItsSubclassRows(): void
    {
        $manager = $this->manager();

        self::assertSame(['c1', 's1'], $this->ids($manager->getRepository(Motorized::class)->findAll()));
        self::assertSame('SELECT `e`.* FROM `wp_sympress_vehicles` `e` WHERE `e`.' . self::CARS, $this->database->selects[0]);
        self::assertSame('DELETE `m` FROM `wp_sympress_vehicles` `m` WHERE `m`.' . self::CARS, $manager->createQuery('DELETE FROM Motorized m')->getSQL());

        // No mapped class is or extends Trailer, so no row can belong to it.
        self::assertSame([], $manager->getRepository(Trailer::class)->findAll());
        self::assertSame('SELECT `e`.* FROM `wp_sympress_vehicles` `e` WHERE 1 = 0', $this->database->selects[1]);
        self::assertSame('DELETE `t` FROM `wp_sympress_vehicles` `t` WHERE 1 = 0', $manager->createQuery('DELETE FROM Trailer t')->getSQL());
    }

    public function testFindAndReferencesOfASubclassIgnoreRowsOfAnotherClass(): void
    {
        $manager = $this->manager();

        self::assertNull($manager->find(Car::class, 'v1'));
        self::assertInstanceOf(SportsCar::class, $manager->find(Car::class, 's1'));

        $reference = $manager->getReference(SportsCar::class, 'c1');
        $this->expectException(EntityNotFoundException::class);
        $reference->garage;
    }

    public function testToManyCollectionsOfASubclassAreFiltered(): void
    {
        $manager = $this->manager();
        $garage = new Garage('g1');
        $cars = $this->association(Garage::class, 'cars');
        $rentals = $this->association(Garage::class, 'rentals');

        self::assertSame(['c1', 's1'], $this->ids($manager->loadAssociationCollection($garage, $cars)));
        self::assertStringEndsWith("WHERE `garage_id` = 'g1' AND " . self::CARS, $this->database->selects[0]);
        $manager->loadAssociationCollection($garage, $rentals);
        self::assertStringEndsWith("WHERE `j`.`garage_id` = 'g1' AND `e`." . self::CARS, $this->database->selects[1]);
        $manager->countAssociationCollection($garage, $cars);
        self::assertSame(["SELECT COUNT(*) FROM `wp_sympress_vehicles` WHERE `garage_id` = 'g1' AND " . self::CARS], $this->database->queries);
    }

    public function testSecondLevelCacheDoesNotAnswerASubclassLookupWithAnotherClass(): void
    {
        $manager = $this->manager(new ArrayCache());

        self::assertInstanceOf(Vehicle::class, $manager->find(Vehicle::class, 'v1'));
        self::assertInstanceOf(Car::class, $manager->find(Vehicle::class, 'c1'));
        $manager->clear();
        $this->database->resultRows = [];

        self::assertInstanceOf(Car::class, $manager->find(Car::class, 'c1'), 'The cached car still answers.');
        self::assertNull($manager->find(Car::class, 'v1'));
        self::assertNull($manager->find(SportsCar::class, 'c1'));
    }

    public function testOrphanRemovalDeletesChildrenOfASubclassOfTheTarget(): void
    {
        $this->database->garages = [['id' => 'g1']];
        $manager = $this->manager();
        $garage = $manager->find(Garage::class, 'g1');
        self::assertInstanceOf(Garage::class, $garage);

        foreach ($garage->cars->toArray() as $car) {
            $garage->cars->removeElement($car);
        }

        $manager->flush();

        // s1 is a SportsCar, a subclass of the association's target Car.
        self::assertSame(['c1', 's1'], array_map(static fn (array $deleted): mixed => $deleted['where']['id'], $this->database->deleted));
    }

    public function testIdentityMapAnswersLookupsThroughAnyClassOfTheHierarchy(): void
    {
        $manager = $this->manager();
        $sportsCar = $manager->find(Car::class, 's1');
        $car = $manager->find(Vehicle::class, 'c1');
        self::assertInstanceOf(SportsCar::class, $sportsCar);
        self::assertInstanceOf(Car::class, $car);
        $this->database->selects = [];

        self::assertSame($sportsCar, $manager->find(Vehicle::class, 's1'));
        self::assertSame($sportsCar, $manager->find(SportsCar::class, 's1'));
        self::assertSame($sportsCar, $manager->getReference(Vehicle::class, 's1'));
        self::assertSame($car, $manager->getReference(Car::class, 'c1'));
        // c1 is a managed Car, so it is no SportsCar.
        self::assertNull($manager->find(SportsCar::class, 'c1'));
        self::assertSame([], $this->database->selects);

        $this->expectException(EntityNotFoundException::class);
        $manager->getReference(SportsCar::class, 'c1');
    }

    public function testUnloadedReferenceOfAnotherClassDoesNotAnswerAHierarchyLookup(): void
    {
        $manager = $this->manager();
        // A guess: the row c1 is a Car, not a SportsCar.
        $guess = $manager->getReference(SportsCar::class, 'c1');

        $car = $manager->find(Vehicle::class, 'c1');

        self::assertInstanceOf(Car::class, $car);
        self::assertNotSame($guess, $car);
    }

    private function manager(?ArrayCache $cache = null): EntityManager
    {
        $metadata = new MetadataFactory();
        $registry = new EntityClassRegistry($metadata, classes: [Vehicle::class, Motorized::class, Car::class, SportsCar::class, Trailer::class, Garage::class]);

        return new EntityManager($metadata, $registry, new EntityHydrator(), $this->database, secondLevelCache: $cache);
    }

    /** @param class-string $className */
    private function association(string $className, string $property): AssociationMetadata
    {
        $association = (new MetadataFactory())->getMetadataFor($className)->associationForProperty($property);
        self::assertInstanceOf(AssociationMetadata::class, $association);

        return $association;
    }

    /**
     * @param list<object|array<string, mixed>> $vehicles
     * @return list<string>
     */
    private function ids(array $vehicles): array
    {
        return array_map(static fn (object|array $vehicle): string => $vehicle instanceof Vehicle ? $vehicle->id : '', $vehicles);
    }
}
