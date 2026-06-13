<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\EntityManager;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Schema\SchemaSqlGenerator;
use SymPress\Orm\Schema\SchemaTool;
use SymPress\Orm\Tests\Fixtures\Course;
use SymPress\Orm\Tests\Fixtures\MutableEmailLog;
use SymPress\Orm\Tests\Fixtures\Student;

final class OrmWorkflowTest extends TestCase
{
    public function testEntityManagerPersistsUpdatesAndFindsRowsThroughWpdb(): void
    {
        $database = new \wpdb();
        $entityManager = $this->entityManager([MutableEmailLog::class, Course::class], $database);
        $log = new MutableEmailLog('log-1', new \DateTimeImmutable('2026-06-13 10:00:00'), 'queued');

        $entityManager->persist($log);
        $entityManager->flush();

        self::assertSame('wp_sympress_mailer_logs', $database->inserted[0]['table']);
        self::assertSame('queued', $database->inserted[0]['data']['status']);

        $database->updated = [];
        $log->status = 'sent';
        $entityManager->flush();

        self::assertSame(['status' => 'sent'], $database->updated[0]['data']);

        $database->resultRows = [
            ['id' => 'course-1', 'title' => 'Math'],
        ];
        $entityManager->clear();

        $course = $entityManager->getRepository(Course::class)->find('course-1');

        self::assertInstanceOf(Course::class, $course);
        self::assertSame('Math', $course->title);
    }

    public function testSchemaAndManyToManyQueryWorkflowUseWordPressTables(): void
    {
        $database = new \wpdb();
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [Student::class, Course::class]);
        $entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), $database);
        $schemaTool = new SchemaTool($metadataFactory, $registry, new SchemaSqlGenerator(), $database);

        $schemaSql = implode("\n\n", $schemaTool->getCreateSchemaSql());

        self::assertStringContainsString('CREATE TABLE wp_sympress_students', $schemaSql);
        self::assertStringContainsString('CREATE TABLE wp_sympress_student_courses', $schemaSql);

        $database->resultRows = [
            ['id' => 'student-1', 'name' => 'Grace'],
        ];

        $query = $entityManager
            ->createQueryBuilder()
            ->select('s')
            ->from(Student::class, 's')
            ->join('s.courses', 'c')
            ->where('c.title = :title')
            ->setParameter('title', 'Math')
            ->getQuery();

        self::assertStringContainsString('wp_sympress_student_courses', $query->getSQL());

        $students = $query->getResult();

        self::assertCount(1, $students);
        self::assertInstanceOf(Student::class, $students[0]);
        self::assertSame('Grace', $students[0]->name);
    }

    /**
     * @param list<class-string> $classes
     */
    private function entityManager(array $classes, \wpdb $database): EntityManager
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: $classes);

        return new EntityManager($metadataFactory, $registry, new EntityHydrator(), $database);
    }
}
