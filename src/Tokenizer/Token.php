<?php

declare(strict_types=1);

namespace RedundantRequireOnce\Tokenizer;

/**
 * Value object representing a PHP token.
 *
 * It provides a unified representation for both array tokens returned by
 * token_get_all() (array{int, string, int}) and single-character string tokens.
 * For single-character tokens, id is null and line is 0.
 */
final class Token
{
    public function __construct(
        public ?int   $id,
        public string $text,
        public int    $line,
    ) {
    }

    private static function fromRaw(array|string $raw): self
    {
        if (is_string($raw)) {
            return new self(null, $raw, 0);
        }
        return new self($raw[0], $raw[1], $raw[2]);
    }

    /**
     * Tokenizes PHP source code.
     *
     * @return list<self>
     */
    public static function tokenize(string $code): array
    {
        return array_map(
            static fn (array|string $raw): self => self::fromRaw($raw),
            token_get_all($code),
        );
    }
}
