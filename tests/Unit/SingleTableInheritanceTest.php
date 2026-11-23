<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\EntityManager;
use SymPress\Orm\Exception\EntityNotFoundException;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Tests\Fixtures\Car;
use SymPress\Orm\Tests\Fixtures\Garage;
use SymPress\Orm\Tests\Fixtures\SportsCar;
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

            public function get_results(string $query, string|int $output = ARRAY_A): array
            {
                $this->selects[] = $query;
                $kinds = preg_match('/`kind` IN \(([^)]*)\)/', $query, $match) === 1
                    ? array_map(static fn (string $kind): string => trim($kind, " '"), explode(',', $match[1]))
                    : null;
                $id = preg_match("/`id` = '([^']*)'/", $query, $match) === 1 ? $match[1] : null;

                return array_values(array_filter(
                    $this->resultRows,
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

    public function testFindAndReferencesOfASubclassIgnoreRowsOfAnotherClass(): void
    {
        $manager = $this->manager();

        self::assertNull($manager->find(Car::class, 'v1'));
        self::assertInstanceOf(SportsCar::class, $manager->find(Car::class, 's1'));

        $reference = $manager->getReference(SportsCar::class, 'c1');
        $this->expectException(EntityNotFoundException::class);
        $reference->garage;
    }

    private function manager(): EntityManager
    {
        $metadata = new MetadataFactory();
        $registry = new EntityClassRegistry($metadata, classes: [Vehicle::class, Car::class, SportsCar::class, Garage::class]);

        return new EntityManager($metadata, $registry, new EntityHydrator(), $this->database);
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
