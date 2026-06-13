<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\EntityManager;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Tests\Fixtures\Course;
use SymPress\Orm\Tests\Fixtures\EmailLog;
use SymPress\Orm\Tests\Fixtures\Student;
use SymPress\Orm\Tests\Fixtures\Tenant;
use SymPress\Orm\Tests\Fixtures\TenantPost;

final class QueryBuilderTest extends TestCase
{
    public function testItCompilesEntityFieldsAndParametersToWpdbSql(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [EmailLog::class]);
        $entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), new \wpdb());

        $compiled = $entityManager
            ->createQueryBuilder()
            ->select('l')
            ->from(EmailLog::class, 'l')
            ->where('l.status = :status')
            ->setParameter('status', 'queued')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults(10)
            ->compile();

        self::assertSame(
            'SELECT `l`.* FROM `wp_sympress_mailer_logs` `l` WHERE `l`.`status` = %s ORDER BY `l`.`created_at` DESC LIMIT %d',
            $compiled->sql,
        );
        self::assertSame(['queued', 10], $compiled->parameters);
    }

    public function testItCompilesPositionalDqlParameters(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [EmailLog::class]);
        $entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), new \wpdb());

        $query = $entityManager->createQuery(
            'SELECT l FROM EmailLog l WHERE l.status = ?1 ORDER BY l.createdAt DESC',
            [1 => 'queued'],
        );

        self::assertSame(
            "SELECT `l`.* FROM `wp_sympress_mailer_logs` `l` WHERE `l`.`status` = 'queued' ORDER BY `l`.`created_at` DESC",
            $query->getSQL(),
        );
    }

    public function testItCompilesDqlUpdateAndDeleteQueries(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [EmailLog::class]);
        $database = new \wpdb();
        $entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), $database);

        $entityManager
            ->createQuery('UPDATE EmailLog l SET l.status = :status WHERE l.id = :id', [
                'status' => 'sent',
                'id' => 'log-1',
            ])
            ->execute();

        $entityManager
            ->createQuery('DELETE FROM EmailLog l WHERE l.id = ?1', [1 => 'log-1'])
            ->execute();

        self::assertSame(
            [
                "UPDATE `wp_sympress_mailer_logs` `l` SET `l`.`status` = 'sent' WHERE `l`.`id` = 'log-1'",
                "DELETE `l` FROM `wp_sympress_mailer_logs` `l` WHERE `l`.`id` = 'log-1'",
            ],
            $database->queries,
        );
    }

    public function testItCompilesManyToManyJoins(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [Student::class, Course::class]);
        $entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), new \wpdb());

        $compiled = $entityManager
            ->createQueryBuilder()
            ->select('s')
            ->from(Student::class, 's')
            ->join('s.courses', 'c')
            ->where('c.title = :title')
            ->setParameter('title', 'Math')
            ->compile();

        self::assertSame(
            'SELECT `s`.* FROM `wp_sympress_students` `s` INNER JOIN `wp_sympress_student_courses` `c_join` ON `s`.`id` = `c_join`.`student_id` INNER JOIN `wp_sympress_courses` `c` ON `c`.`id` = `c_join`.`course_id` WHERE `c`.`title` = %s',
            $compiled->sql,
        );
        self::assertSame(['Math'], $compiled->parameters);
    }

    public function testItExpandsArrayParametersForInExpressions(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [EmailLog::class]);
        $entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), new \wpdb());

        $compiled = $entityManager
            ->createQueryBuilder()
            ->select('l')
            ->from(EmailLog::class, 'l')
            ->where('l.status IN (:statuses)')
            ->setParameter('statuses', ['queued', 'sent'])
            ->compile();

        self::assertSame(
            'SELECT `l`.* FROM `wp_sympress_mailer_logs` `l` WHERE `l`.`status` IN (%s, %s)',
            $compiled->sql,
        );
        self::assertSame(['queued', 'sent'], $compiled->parameters);
    }

    public function testItRejectsUnsafeOrderByFragments(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [EmailLog::class]);
        $entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), new \wpdb());

        $this->expectException(\InvalidArgumentException::class);

        $entityManager
            ->createQueryBuilder()
            ->select('l')
            ->from(EmailLog::class, 'l')
            ->orderBy('l.status DESC, SLEEP(1)');
    }

    public function testDqlCompilerRejectsUnsafeOrderByFragments(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [EmailLog::class]);
        $entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), new \wpdb());

        $this->expectException(\InvalidArgumentException::class);

        $entityManager->createQuery('SELECT l FROM EmailLog l ORDER BY l.status DESC, SLEEP(1)');
    }

    public function testRepositoryRejectsUnsafeCriteriaFields(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [EmailLog::class]);
        $entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), new \wpdb());

        $this->expectException(\InvalidArgumentException::class);

        $entityManager->getRepository(EmailLog::class)->findBy([
            'status OR 1=1' => 'queued',
        ]);
    }

    public function testItCompilesCompositeJoinColumns(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [Tenant::class, TenantPost::class]);
        $entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), new \wpdb());

        $compiled = $entityManager
            ->createQueryBuilder()
            ->select('p')
            ->from(TenantPost::class, 'p')
            ->join('p.tenant', 't')
            ->where('t.name = :name')
            ->setParameter('name', 'Acme')
            ->compile();

        self::assertSame(
            'SELECT `p`.* FROM `wp_sympress_tenant_posts` `p` INNER JOIN `wp_sympress_tenants` `t` ON `p`.`tenant_id` = `t`.`tenant_id` AND `p`.`tenant_code` = `t`.`code` WHERE `t`.`name` = %s',
            $compiled->sql,
        );
        self::assertSame(['Acme'], $compiled->parameters);
    }

    public function testItCompilesRegisteredDqlFunctionsAndOutputWalkers(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [EmailLog::class]);
        $entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), new \wpdb());
        $entityManager->registerDqlFunction('DATE_YEAR', static fn (string $argument): string => 'YEAR(' . $argument . ')');
        $entityManager->addOutputWalker(static fn (string $sql): string => $sql . ' /* traced */');

        $query = $entityManager->createQuery(
            'SELECT l FROM EmailLog l WHERE DATE_YEAR(l.createdAt) = :year',
            ['year' => 2026],
        );

        self::assertSame(
            "SELECT `l`.* FROM `wp_sympress_mailer_logs` `l` WHERE YEAR(`l`.`created_at`) = '2026' /* traced */",
            $query->getSQL(),
        );
    }
}
