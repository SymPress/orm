<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests;

/**
 * The wpdb::prepare() rules the ORM relies on, shared by the wpdb test double and the database parity test.
 *
 * It returns the SQL with literal % signs, as wpdb::remove_placeholder_escape() does before a query runs.
 */
final class WpdbPrepare
{
    // What mysqli_real_escape_string() escapes for utf8mb4 connections.
    private const array ESCAPES = [
        '\\' => '\\\\',
        "\0" => '\\0',
        "\n" => '\\n',
        "\r" => '\\r',
        "'" => "\\'",
        '"' => '\\"',
        "\x1a" => '\\Z',
    ];

    public static function prepare(string $query, mixed ...$args): string
    {
        $format = '(?:[1-9][0-9]*[$])?[-+0-9]*(?: |0|\'.)?[-+0-9]*(?:\.[0-9]+)?';
        $query = str_replace(["'%s'", '"%s"'], '%s', $query);
        $query = (string) preg_replace("/%(?:%|$|(?!({$format})?[sdfFi]))/", '%%\\1', $query);
        $parts = (array) preg_split("/(^|[^%]|(?:%%)+)(%(?:{$format})?[sdfFi])/", $query, -1, PREG_SPLIT_DELIM_CAPTURE);
        $placeholderCount = intdiv(count($parts) - 1, 3);
        $passedAsArray = count($args) === 1 && is_array($args[0] ?? null);
        $args = $passedAsArray ? array_values($args[0]) : array_values($args);
        $sql = '';
        $maxNumbered = 0;

        for ($key = 2; $key < count($parts); $key += 3) {
            $placeholder = (string) $parts[$key];
            $spec = substr($placeholder, 1, -1);
            $type = substr($placeholder, -1);
            $preceding = (string) $parts[$key - 1];
            $maxNumbered = max($maxNumbered, (int) substr($placeholder, 1));

            if ($type === 'i' || ($type === 'f' && str_ends_with($preceding, '%'))) {
                throw new \LogicException(sprintf('The wpdb test double does not support "%s".', $placeholder));
            }

            if ($type === 'f') {
                // wpdb forces floats to be locale-unaware.
                $placeholder = '%' . $spec . 'F';
            } elseif ($type === 's' && $spec === '' && !str_ends_with($preceding, '%')) {
                $placeholder = "'%s'";
            }

            $sql .= $parts[$key - 2] . $preceding . $placeholder;
        }

        $sql .= $parts[count($parts) - 1];

        // wpdb returns null or '' instead of failing when placeholders and arguments do not match.
        if (count($args) !== $placeholderCount && $placeholderCount === 1 && $passedAsArray) {
            return '';
        }

        if (count($args) < $placeholderCount && ($maxNumbered === 0 || count($args) < $maxNumbered)) {
            return '';
        }

        return vsprintf($sql, array_map(
            // Like wpdb::_real_escape(), non-scalar values (objects, arrays, null) become ''.
            static fn (mixed $value): int|float|string => is_int($value) || is_float($value)
                ? $value
                : strtr(is_scalar($value) ? (string) $value : '', self::ESCAPES),
            $args,
        ));
    }
}
