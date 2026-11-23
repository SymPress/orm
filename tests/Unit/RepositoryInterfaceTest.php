<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\Query\Criteria;
use SymPress\Orm\RepositoryInterface;
use SymPress\Orm\Tests\Fixtures\MutableEmailLog;

final class RepositoryInterfaceTest extends TestCase
{
    public function testRepositoryDoubleServesCodeTypedAgainstTheInterface(): void
    {
        $log = new MutableEmailLog('log-1', new \DateTimeImmutable('2026-06-13 10:00:00'), 'queued');
        $repository = new class ([$log]) implements RepositoryInterface {
            /** @param list<MutableEmailLog> $logs */
            public function __construct(private readonly array $logs)
            {
            }

            public function find(mixed $id): ?object
            {
                return array_find($this->logs, static fn (MutableEmailLog $log): bool => $log->id === $id);
            }

            public function findAll(): array
            {
                return $this->logs;
            }

            public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
            {
                return array_values(array_filter(
                    $this->logs,
                    static fn (MutableEmailLog $log): bool => $log->status === ($criteria['status'] ?? $log->status),
                ));
            }

            public function findOneBy(array $criteria): ?object
            {
                return $this->findBy($criteria)[0] ?? null;
            }

            public function matching(Criteria $criteria): array
            {
                return $this->logs;
            }
        };
        $pendingIds = static fn (RepositoryInterface $logs): array => array_map(
            static fn (object $log): string => $log instanceof MutableEmailLog ? $log->id : '',
            $logs->findBy(['status' => 'queued']),
        );

        self::assertSame(['log-1'], $pendingIds($repository));
        self::assertSame($log, $repository->find('log-1'));
        self::assertNull($repository->findOneBy(['status' => 'sent']));
    }
}
