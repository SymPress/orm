<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SymPress\Orm\Tests\WpdbPrepare;

final class WpdbPrepareParityTest extends TestCase
{
    /** @param list<mixed> $args */
    #[DataProvider('cases')]
    public function testTestDoublePreparesLikeRealWpdb(string $query, array $args): void
    {
        $database = $GLOBALS['wpdb'];
        self::assertInstanceOf(\wpdb::class, $database);

        self::assertSame(
            $database->remove_placeholder_escape((string) $database->prepare($query, ...$args)),
            WpdbPrepare::prepare($query, ...$args),
        );
    }

    /** @return iterable<string, array{string, list<mixed>}> */
    public static function cases(): iterable
    {
        yield 'integer' => ['SELECT %d', [2026]];
        yield 'numeric string as integer' => ['SELECT %d', ['2026']];
        yield 'non-numeric string as integer' => ['SELECT %d', ['abc']];
        yield 'booleans as integers' => ['SELECT %d, %d', [true, false]];
        yield 'null as integer and string' => ['SELECT %d, %s', [null, null]];
        yield 'quotes and backslashes' => ['SELECT %s', ['O\'Reilly "quoted" C:\\temp\\']];
        yield 'control characters' => ['SELECT %s', ["line\nbreak\r\0\x1a"]];
        yield 'object as string' => ['SELECT %s', [new \stdClass()]];
        yield 'float' => ['SELECT %f', [0.0000125]];
        yield 'float string' => ['SELECT %s', ['0.0000125']];
        yield 'pre-quoted placeholder' => ["SELECT '%s', \"%s\"", ['a', 'b']];
        yield 'escaped percent' => ["SELECT 'que%%' LIKE %s", ['que%']];
        yield 'unknown percent' => ["SELECT DATE_FORMAT(NOW(), '%Y') = %s", ['2026']];
        yield 'placeholder after percent' => ["SELECT 'abc' LIKE '%%%s%%'", ['b']];
        yield 'string placeholder after escaped percent' => ['SELECT 7 %%%s = 1', ['2 OR 1=1']];
        yield 'string placeholder spaced from escaped percent' => ['SELECT 7 %% %s = 1', ['2 OR 1=1']];
        yield 'placeholder between literals' => ["SELECT 'a'%s'b', 'a' %s 'b'", ['x', 'y']];
        yield 'too few arguments' => ['SELECT %s, %s', ['one']];
        yield 'too many arguments' => ['SELECT %s', ['one', 'two']];
        yield 'arguments as one array' => ['SELECT %s, %d', [['one', 2]]];
    }
}
