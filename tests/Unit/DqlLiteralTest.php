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
    public function testDqlSelectSplitsClausesOutsideLiterals(): void
    {
        $sql = $this->manager()->createQuery(
            "SELECT a FROM Article a JOIN a.author u WITH u.name = 'x WHERE y' WHERE a.title = 'p JOIN a.author q GROUP BY a.id HAVING FROM'"
            . ' AND a.id = :id ORDER BY a.title DESC',
            ['id' => 'a-1'],
        )->getSQL();

        self::assertStringContainsString("AND `u`.`name` = 'x WHERE y' WHERE", $sql);
        self::assertStringEndsWith(
            "WHERE `a`.`title` = 'p JOIN a.author q GROUP BY a.id HAVING FROM' AND `a`.`id` = 'a-1' ORDER BY `a`.`title` DESC",
            $sql,
        );
    }

    public function testDqlSelectKeepsOrderByInsideLiteral(): void
    {
        self::assertStringEndsWith(
            "WHERE `l`.`status` = 'x ORDER BY y' ORDER BY `l`.`created_at` DESC",
            $this->manager()->createQuery("SELECT l FROM EmailLog l WHERE l.status = 'x ORDER BY y' ORDER BY l.createdAt DESC")->getSQL(),
        );
    }

    public function testDqlUpdateAndDeleteSplitOutsideLiterals(): void
    {
        $manager = $this->manager();

        self::assertSame(
            "UPDATE `wp_sympress_mailer_logs` `l` SET `l`.`status` = 'a WHERE b' WHERE `l`.`id` = 'log-1'",
            $manager->createQuery("UPDATE EmailLog l SET l.status = 'a WHERE b' WHERE l.id = :id", ['id' => 'log-1'])->getSQL(),
        );
        self::assertSame(
            "DELETE `l` FROM `wp_sympress_mailer_logs` `l` WHERE `l`.`status` = 'SET x FROM y WHERE z'",
            $manager->createQuery("DELETE FROM EmailLog l WHERE l.status = 'SET x FROM y WHERE z'")->getSQL(),
        );
    }

    public function testDqlKeepsWhitespaceInsideLiterals(): void
    {
        self::assertStringEndsWith(
            "WHERE `l`.`status` = 'a  b\tc' AND `l`.`id` = 'x'",
            $this->manager()->createQuery("SELECT l FROM EmailLog l   WHERE l.status = 'a  b\tc'\n AND l.id = 'x'")->getSQL(),
        );
    }

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
