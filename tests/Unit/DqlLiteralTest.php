<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\EntityManager;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Tests\Fixtures\Article;
use SymPress\Orm\Tests\Fixtures\Author;
use SymPress\Orm\Tests\Fixtures\EmailLog;

final class DqlLiteralTest extends TestCase
{
    public function testFieldPathsAndParametersInsideLiteralsAreLeftAlone(): void
    {
        $manager = $this->manager();
        $expected = "WHERE `l`.`status` = 'see l.status at 10:30 for :name and ?1'"
            . " AND `l`.`id` = 'log-1' AND `l`.`status` <> \"l.id = :id\" AND `l`.`id` <> 'it''s :id \\' l.id'";
        $where = "l.status = 'see l.status at 10:30 for :name and ?1'"
            . " AND l.id = :id AND l.status <> \"l.id = :id\" AND l.id <> 'it''s :id \\' l.id'";

        self::assertStringEndsWith($expected, $manager->createQuery('SELECT l FROM EmailLog l WHERE ' . $where, ['id' => 'log-1'])->getSQL());
        self::assertStringEndsWith(
            $expected,
            $manager->createQueryBuilder()->select('l')->from(EmailLog::class, 'l')->where($where)->setParameter('id', 'log-1')->getQuery()->getSQL(),
        );
    }

    public function testDqlFunctionsAreNotRewrittenInsideLiterals(): void
    {
        $manager = $this->manager();
        $manager->registerDqlFunction('DATE_YEAR', static fn (string $argument): string => 'YEAR(' . $argument . ')');

        self::assertStringEndsWith(
            "WHERE YEAR(`l`.`created_at`) = 2026 AND `l`.`status` <> 'DATE_YEAR(l.createdAt) (' AND YEAR('a)') = 1",
            $manager->createQuery(
                "SELECT l FROM EmailLog l WHERE DATE_YEAR(l.createdAt) = :year AND l.status <> 'DATE_YEAR(l.createdAt) (' AND DATE_YEAR('a)') = 1",
                ['year' => 2026],
            )->getSQL(),
        );
    }

    private function manager(): EntityManager
    {
        $metadata = new MetadataFactory();
        $registry = new EntityClassRegistry($metadata, classes: [EmailLog::class, Article::class, Author::class]);

        return new EntityManager($metadata, $registry, new EntityHydrator(), new \wpdb());
    }
}
