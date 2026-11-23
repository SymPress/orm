<?php

declare(strict_types=1);

namespace SymPress\Orm\Query;

/**
 * Separates an SQL fragment into code and quoted literals, so that rewriting and clause splitting see code only.
 *
 * Each '...', "..." or `...` literal is replaced by a token "\x01<index>\x01" that no rewrite pattern matches, and
 * restore() puts the literals back byte for byte.
 *
 * @internal
 */
final readonly class LiteralMask
{
    private const string TOKEN = "\x01";

    /** @param list<string> $literals */
    private function __construct(
        public string $code,
        private array $literals,
    ) {
    }

    public static function mask(string $fragment): self
    {
        if (str_contains($fragment, self::TOKEN)) {
            throw new \InvalidArgumentException('Unsupported control character in DQL expression.');
        }

        $literals = [];
        // A doubled quote stays inside the literal; a backslash escapes the next byte in '...' and "..." only.
        $code = preg_replace_callback(
            '/\'(?:[^\'\\\\]++|\\\\.|\'\')*+\'|"(?:[^"\\\\]++|\\\\.|"")*+"|`(?:[^`]++|``)*+`/s',
            static function (array $matches) use (&$literals): string {
                $literals[] = $matches[0];

                return self::TOKEN . (count($literals) - 1) . self::TOKEN;
            },
            $fragment,
        );

        if ($code === null) {
            throw new \InvalidArgumentException(sprintf('Cannot scan DQL expression "%s".', $fragment));
        }

        // The leftmost quote that does not open a complete literal stays in the code.
        if (strpbrk($code, '\'"`') !== false) {
            throw new \InvalidArgumentException(sprintf('Unterminated string literal in DQL expression "%s".', $fragment));
        }

        return new self($code, $literals);
    }

    public function restore(string $code): string
    {
        return preg_replace_callback(
            '/\x01(\d+)\x01/',
            fn (array $matches): string => $this->literals[(int) $matches[1]],
            $code,
        ) ?? $code;
    }
}
