<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\EntityManager;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Query\Criteria;
use SymPress\Orm\Query\QueryBuilder;
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

    #[DataProvider('rejectedFragments')]
    public function testDqlRejectsStatementSyntaxInFragments(string $fragment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/are rejected|Unterminated string literal/');

        $this->manager()->createQuery('SELECT l FROM EmailLog l WHERE ' . $fragment, ['id' => 'x'])->getSQL();
    }

    #[DataProvider('rejectedFragments')]
    public function testQueryBuilderRejectsStatementSyntaxInFragments(string $fragment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/are rejected|Unterminated string literal/');

        // A comment would also cut off whatever is appended later, such as this tenant filter.
        $this->manager()->createQueryBuilder()->select('l')->from(EmailLog::class, 'l')
            ->where($fragment)->andWhere("l.status = 'tenant'")->setParameter('id', 'x')->compile();
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedFragments(): iterable
    {
        yield 'statement separator' => ['l.id = :id; DROP TABLE wp_users'];
        yield 'trailing separator' => ['l.id = :id;'];
        yield 'subselect' => ['l.id IN (SELECT ID FROM wp_users)'];
        yield 'lowercase subselect' => ['l.id in (select ID from wp_users)'];
        yield 'union' => ['l.id = :id UNION ALL (l.id)'];
        yield 'block comment' => ['l.id = :id /* x */'];
        yield 'executable comment' => ['l.id = :id /*!50000 OR 1 = 1 */'];
        yield 'hash comment' => ['l.id = :id #x'];
        yield 'dash comment' => ['l.id = :id -- x'];
        yield 'dash comment at the end' => ['l.id = :id --'];
        yield 'dash comment before a control character' => ["l.id = :id --\x02OR 1 = 1"];
        yield 'unterminated literal' => ["l.status = 'abc"];
        yield 'unterminated escaped literal' => ["l.status = 'abc\\'"];
    }

    /** @param \Closure(EntityManager): mixed $compile */
    #[DataProvider('rejectedFragmentMethods')]
    public function testEveryFragmentMethodRejectsStatementSyntax(\Closure $compile): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/are rejected|Unterminated string literal/');

        $compile($this->manager());
    }

    /** @return iterable<string, array{\Closure(EntityManager): mixed}> */
    public static function rejectedFragmentMethods(): iterable
    {
        $logs = static fn (EntityManager $orm): QueryBuilder => $orm->createQueryBuilder()->select('l')->from(EmailLog::class, 'l');
        $articles = static fn (EntityManager $orm): QueryBuilder => $orm->createQueryBuilder()->select('a')->from(Article::class, 'a');
        $criteria = Criteria::create()->where('e.id = 1 UNION ALL (e.id)');

        yield 'select' => [static fn (EntityManager $orm): mixed => $logs($orm)->select('(SELECT 1)')->compile()];
        yield 'groupBy' => [static fn (EntityManager $orm): mixed => $logs($orm)->groupBy('l.status; x')->compile()];
        yield 'having' => [static fn (EntityManager $orm): mixed => $logs($orm)->having('COUNT(l.id) > 1 -- x')->compile()];
        yield 'join condition' => [static fn (EntityManager $orm): mixed => $articles($orm)->join('a.author', 'u', 'u.name = 1 /* x */')->compile()];
        yield 'criteria' => [static fn (EntityManager $orm): mixed => $orm->getRepository(EmailLog::class)->matching($criteria)];
        yield 'dql select list' => [static fn (EntityManager $orm): mixed => $orm->createQuery('SELECT (SELECT 1) FROM EmailLog l')->getSQL()];
        yield 'dql update' => [static fn (EntityManager $orm): mixed => $orm->createQuery("UPDATE EmailLog l SET l.status = 'x' WHERE l.id = 1; DROP TABLE t")];
        yield 'dql delete' => [static fn (EntityManager $orm): mixed => $orm->createQuery('DELETE FROM EmailLog l WHERE l.id = 1 # x')];
    }

    public function testLookalikesOfRejectedSyntaxStillCompile(): void
    {
        $manager = $this->manager();
        $where = "l.status = 'a; -- # /* SELECT UNION' AND l.id = 5--1 AND l.id = 5--'1' AND e.select = \"x;\"";
        $expected = "WHERE `l`.`status` = 'a; -- # /* SELECT UNION' AND `l`.`id` = 5--1 AND `l`.`id` = 5--'1' AND e.select = \"x;\"";

        self::assertStringEndsWith($expected, $manager->createQuery('SELECT l FROM EmailLog l WHERE ' . $where)->getSQL());
        self::assertStringEndsWith($expected, $manager->createQueryBuilder()->select('l')->from(EmailLog::class, 'l')->where($where)->getQuery()->getSQL());
    }

    private function manager(): EntityManager
    {
        $metadata = new MetadataFactory();
        $registry = new EntityClassRegistry($metadata, classes: [EmailLog::class, Article::class, Author::class]);

        return new EntityManager($metadata, $registry, new EntityHydrator(), new \wpdb());
    }
}
